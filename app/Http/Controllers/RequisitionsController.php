<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\CsiSchedule;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Supplier;
use App\Services\RequisitionBasket;
use App\Services\RequisitionPromotion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The PO builder and the requisitions it produces.
 *
 * The workflow this serves runs outside this system for one step in the
 * middle: we price a basket here from the reseller's catalog, print it,
 * someone keys it into Colleague by hand, and Colleague issues a REQM
 * requisition number. That REQM comes back here and becomes the handle for
 * the order until finance issues the P00… purchase order number, at which
 * point the requisition is linked to its row in the purchase order ledger.
 *
 * Requisitions therefore never touch the budget reports on their own — only
 * the purchase order they eventually resolve to does.
 */
class RequisitionsController extends Controller
{
    /**
     * The PO builder: a catalog on the left, a basket on the right.
     *
     * Opens empty for a new requisition, or hydrated from an existing draft
     * when `?requisition=<id>` is supplied, so a basket can be put down and
     * picked up again.
     *
     * `?fiscal_year=FY2026-27` preselects the year, which is how the button
     * on the purchase order list arrives here — landing on the current year
     * rather than on a blank picker nobody remembers to set.
     */
    public function builder(Request $request)
    {
        $this->authorize('create', Requisition::class);

        $requisition = null;
        if ($request->filled('requisition')) {
            $requisition = Requisition::with('items.catalogItem')
                ->whereKey($request->query('requisition'))
                ->first();
        }

        $supplierId = $request->query('supplier_id', $requisition?->supplier_id);

        $catalog = CatalogItem::with('supplier', 'manufacturer')
            ->active()
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        // A year asked for by name is offered even if nothing has been ordered
        // against it yet — otherwise a deep link to a year the ledger has not
        // seen would silently fall back to blank.
        $fiscalYears = Helper::fiscalYearOptions();
        $selectedFiscalYear = $request->query('fiscal_year') ?: $requisition?->fiscal_year;

        if ($selectedFiscalYear && ! in_array($selectedFiscalYear, $fiscalYears, true)) {
            array_unshift($fiscalYears, $selectedFiscalYear);
        }

        // A capital-request basket edits against a budget: surface the
        // FY's ending-schedule envelope so the builder's live total reads
        // directly against it — the whole point of editing the request
        // here is comparing it to the money while adjusting.
        $capital = $requisition?->capital_request_fy
            ? app(ProcurementReportsController::class)->capitalSummary($requisition->capital_request_fy)
            : null;

        return view('reports/procurement/po-builder', [
            'reportTitle' => trans('admin/purchase-orders/general.report_po_builder'),
            'capital' => $capital,
            'requisition' => $requisition,
            'catalog' => $catalog->map(fn (CatalogItem $item) => $this->catalogPayload($item)),
            'basket' => $requisition
                ? $requisition->items->map(fn (RequisitionItem $line) => $this->basketPayload($line))->values()
                : collect(),
            'categories' => $catalog->pluck('category')->filter()->unique()->sort()->values(),
            'suppliers' => Supplier::orderBy('name')->pluck('name', 'id'),
            'companies' => Company::orderBy('name')->pluck('name', 'id'),
            'fiscalYears' => $fiscalYears,
            'selectedFiscalYear' => $selectedFiscalYear,
            'selectedSupplierId' => $supplierId,
        ]);
    }

    /**
     * The catalog row as the builder's JavaScript needs it. effective_cost
     * collapses the quoted/estimated split into the one number a line is
     * priced at; is_estimate keeps the distinction visible in the UI.
     *
     * @return array<string, mixed>
     */
    private function catalogPayload(CatalogItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'category' => $item->category,
            'subcategory' => $item->subcategory,
            'product_type' => $item->product_type,
            'vendor_sku' => $item->vendor_sku,
            'mfr_part_number' => $item->mfr_part_number,
            'unit_cost' => round($item->effectiveCost(), 2),
            'is_estimate' => $item->isEstimate(),
            'is_expired' => $item->isExpired(),
            'quoted_at' => $item->quoted_at?->toDateString(),
            'expires_at' => $item->expires_at?->toDateString(),
            'supplier' => $item->supplier?->name,
            'source' => $item->source,
        ];
    }

    /**
     * A saved line as the builder's JavaScript needs it, so reopening a
     * draft rebuilds exactly the basket that was saved — snapshotted costs
     * and all, not today's catalog price.
     *
     * @return array<string, mixed>
     */
    private function basketPayload(RequisitionItem $line): array
    {
        return [
            'catalog_item_id' => $line->catalog_item_id,
            'description' => $line->description,
            'vendor_sku' => $line->vendor_sku,
            'mfr_part_number' => $line->mfr_part_number,
            'gl_number' => $line->gl_number,
            'quantity' => (int) $line->quantity,
            'unit_of_measure' => $line->unit_of_measure,
            'unit_cost' => round((float) $line->unit_cost, 2),
            'pst_applicable' => (bool) $line->pst_applicable,
            'notes' => $line->notes,
            'is_estimate' => $line->isEstimate(),
        ];
    }

    /**
     * Save a basket. Creates a requisition, or replaces the lines on the
     * one being edited. The write itself lives in RequisitionBasket, which
     * the API shares.
     */
    public function store(Request $request, RequisitionBasket $basket): RedirectResponse
    {
        $this->authorize('create', Requisition::class);

        $validated = $request->validate(RequisitionBasket::rules());

        try {
            $requisition = $basket->save($validated);
        } catch (\RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        // Capital mode: the basket IS the FY's Devices Capital Request, so
        // saving lands back on the capital page where the request reads
        // against its envelope — the builder was just the editor.
        if ($requisition->capital_request_fy) {
            return redirect()->route('reports.procurement.capital-request', ['fiscal_year' => $requisition->capital_request_fy])
                ->with('success', trans('admin/purchase-orders/general.requisition_saved'));
        }

        return redirect()->route('requisitions.show', $requisition->id)
            ->with('success', trans('admin/purchase-orders/general.requisition_saved'));
    }

    /**
     * Every requisition. The rows come from the API so the list sorts,
     * searches and exports like every other table in the app — and so the
     * procurement hub can render the same table without duplicating it.
     */
    public function index()
    {
        $this->authorize('view', Requisition::class);

        return view('requisitions/index');
    }

    public function show(Requisition $requisition)
    {
        $this->authorize('view', Requisition::class);

        $requisition->load('items.catalogItem', 'supplier', 'company', 'purchaseOrder', 'adminuser');

        return view('requisitions/view', [
            'requisition' => $requisition,
            'purchaseOrders' => PurchaseOrder::orderByDesc('po_number')->pluck('po_number', 'id'),
            'fiscalYears' => Helper::fiscalYearOptions(),
            // Read live from the CSI mirror, never from configuration: two
            // schedules open each quarter and roll over, so a hardcoded pair
            // would be stale within three months.
            'leaseSchedules' => CsiSchedule::openScheduleNames(),
        ]);
    }

    /**
     * The PO email has arrived: turn this requisition into a row on the
     * purchase order ledger, or link the row that is already there.
     *
     * The PDF is required rather than encouraged. This is the step that makes
     * the money real — from here on the purchase order holds budget and
     * orders can be allocated against it — and a ledger row whose number
     * cannot be traced back to the document that issued it is exactly the
     * kind of entry the reports later cannot explain.
     */
    public function promote(Request $request, Requisition $requisition, RequisitionPromotion $promotion): RedirectResponse
    {
        $this->authorize('update', Requisition::class);

        $requisition->load('items');

        $existing = $request->filled('purchase_order_id')
            ? PurchaseOrder::find($request->input('purchase_order_id'))
            : null;

        // Re-linking a purchase order that already carries its paperwork does
        // not ask for a second copy of it.
        $documentRule = ($existing && $promotion->hasDocument($existing)) ? 'nullable' : 'required';

        $validated = $request->validate([
            'purchase_order_id' => 'nullable|integer|exists:purchase_orders,id',
            'po_number' => 'required_without:purchase_order_id|nullable|string|max:191',
            'title' => 'nullable|string|max:191',
            'fiscal_year' => 'nullable|string|max:191',
            'cost_center' => 'nullable|string|max:191',
            'budget' => 'nullable|numeric|min:0',
            'order_date' => 'nullable|date',
            'notes' => 'nullable|string|max:65535',
            'document' => $documentRule.'|file|mimes:pdf|max:'.Helper::file_upload_max_size(),
            'document_notes' => 'nullable|string|max:191',
        ], [
            'document.required' => trans('admin/purchase-orders/general.promote_document_required'),
        ]);

        try {
            $purchaseOrder = $promotion->promote($requisition, $validated, $request->file('document'));
        } catch (\RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('purchase-orders.show', $purchaseOrder->id)
            ->with('success', trans('admin/purchase-orders/general.promote_success', ['po' => $purchaseOrder->po_number]));
    }

    /**
     * Move a requisition along its lifecycle: record the REQM number that
     * came back from Colleague, link the purchase order finance issued, or
     * cancel it.
     *
     * Status is derived from what has been recorded rather than set freely —
     * a requisition with a PO number is ordered, one with a REQM is
     * requisitioned. That keeps the list honest without asking the reader to
     * keep a dropdown in sync with the paperwork.
     */
    public function update(Request $request, Requisition $requisition): RedirectResponse
    {
        $this->authorize('update', Requisition::class);

        $validated = $request->validate([
            'requisition_number' => 'sometimes|nullable|string|max:191',
            'status' => 'sometimes|nullable|string|in:'.implode(',', Requisition::STATUSES),
            'notes' => 'sometimes|nullable|string|max:65535',
        ]);

        $hadRequisitionNumber = (bool) $requisition->requisition_number;

        // Only what was submitted is touched. The purchase order link is set
        // by promotion and is deliberately not settable here — a form that
        // omits a field must not be read as a request to clear it.
        foreach (['requisition_number', 'notes'] as $field) {
            if ($request->has($field)) {
                $requisition->{$field} = $validated[$field] ?? null;
            }
        }

        // An explicit status wins (that's how a requisition gets submitted or
        // cancelled); otherwise the paperwork decides.
        if (! empty($validated['status'])) {
            $requisition->status = $validated['status'];
        } elseif ($requisition->purchase_order_id) {
            $requisition->status = 'ordered';
        } elseif ($requisition->requisition_number) {
            $requisition->status = 'requisitioned';
        }

        // Promotion is the only thing that says "ordered", because it is the
        // only thing that produces the purchase order the word refers to.
        if ($requisition->status === 'ordered' && ! $requisition->purchase_order_id) {
            return redirect()->back()->withInput()
                ->with('error', trans('admin/purchase-orders/general.promote_required_for_ordered'));
        }

        if ($requisition->status === 'submitted' && ! $requisition->submitted_at) {
            $requisition->submitted_at = now();
        }

        if ($requisition->requisition_number && ! $hadRequisitionNumber) {
            $requisition->requisitioned_at = now();
        }

        if (! $requisition->save()) {
            return redirect()->back()->withInput()->withErrors($requisition->getErrors());
        }

        return redirect()->route('requisitions.show', $requisition->id)
            ->with('success', trans('admin/purchase-orders/general.requisition_updated'));
    }



    public function destroy(Requisition $requisition): RedirectResponse
    {
        $this->authorize('delete', Requisition::class);

        if ($requisition->status === 'ordered') {
            return redirect()->route('requisitions.show', $requisition->id)
                ->with('error', trans('admin/purchase-orders/general.requisition_locked'));
        }

        $requisition->delete();

        return redirect()->route('requisitions.index')
            ->with('success', trans('admin/purchase-orders/general.requisition_deleted'));
    }

    /**
     * The keying sheet: the requisition laid out the way it has to be typed
     * into Colleague, printable, with no site chrome around it.
     */
    public function print(Requisition $requisition)
    {
        $this->authorize('view', Requisition::class);

        $requisition->load('items.catalogItem', 'supplier', 'company');

        return view('requisitions/print', ['requisition' => $requisition]);
    }

    /**
     * The same lines as CSV, for pasting into whatever finance asks for next.
     */
    public function export(Requisition $requisition): StreamedResponse
    {
        $this->authorize('view', Requisition::class);

        $requisition->load('items');

        $filename = 'requisition-'.($requisition->requisition_number ?: $requisition->id).'.csv';

        return response()->streamDownload(function () use ($requisition) {
            $csv = Writer::createFromStream(fopen('php://output', 'w'));
            $csv->addFormatter(new EscapeFormula);

            $csv->insertOne([
                trans('admin/purchase-orders/general.builder_col_sku'),
                trans('admin/purchase-orders/general.builder_col_mfr'),
                trans('admin/purchase-orders/general.builder_col_description'),
                trans('admin/purchase-orders/general.builder_col_qty'),
                trans('admin/purchase-orders/general.builder_col_unit_cost'),
                trans('admin/purchase-orders/general.builder_col_line_total'),
            ]);

            foreach ($requisition->items as $line) {
                $csv->insertOne([
                    $line->vendor_sku,
                    $line->mfr_part_number,
                    $line->description,
                    $line->quantity,
                    number_format((float) $line->unit_cost, 2, '.', ''),
                    number_format($line->lineTotal(), 2, '.', ''),
                ]);
            }

            $csv->insertOne([]);
            $csv->insertOne(['', '', trans('admin/purchase-orders/general.builder_subtotal'), '', '', number_format($requisition->subtotal(), 2, '.', '')]);
            $csv->insertOne(['', '', trans('admin/purchase-orders/general.builder_shipping'), '', '', number_format((float) $requisition->shipping, 2, '.', '')]);
            $csv->insertOne(['', '', trans('admin/purchase-orders/general.builder_gst'), '', '', number_format($requisition->gstAmount(), 2, '.', '')]);
            $csv->insertOne(['', '', trans('admin/purchase-orders/general.builder_pst'), '', '', number_format($requisition->pstAmount(), 2, '.', '')]);
            $csv->insertOne(['', '', trans('admin/purchase-orders/general.builder_total'), '', '', number_format($requisition->total(), 2, '.', '')]);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
