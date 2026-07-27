<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        return view('reports/procurement/po-builder', [
            'reportTitle' => trans('admin/purchase-orders/general.report_po_builder'),
            'requisition' => $requisition,
            'catalog' => $catalog->map(fn (CatalogItem $item) => $this->catalogPayload($item)),
            'basket' => $requisition
                ? $requisition->items->map(fn (RequisitionItem $line) => $this->basketPayload($line))->values()
                : collect(),
            'categories' => $catalog->pluck('category')->filter()->unique()->sort()->values(),
            'suppliers' => Supplier::orderBy('name')->pluck('name', 'id'),
            'companies' => Company::orderBy('name')->pluck('name', 'id'),
            'fiscalYears' => $this->fiscalYearOptions(),
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
            'quantity' => (int) $line->quantity,
            'unit_cost' => round((float) $line->unit_cost, 2),
            'pst_applicable' => (bool) $line->pst_applicable,
            'notes' => $line->notes,
            'is_estimate' => $line->isEstimate(),
        ];
    }

    /**
     * Save a basket. Creates a requisition, or replaces the lines on the
     * one being edited.
     *
     * Lines are rewritten wholesale rather than diffed: the builder owns the
     * entire basket, a partial line-level merge would need identity the UI
     * doesn't carry, and the write is small enough to be cheap. Only a draft
     * can be rewritten — once a requisition has been keyed into Colleague,
     * its lines are what was keyed and must stay that way.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Requisition::class);

        $validated = $request->validate([
            'requisition_id' => 'nullable|integer|exists:requisitions,id',
            'title' => 'required|string|max:191',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'company_id' => 'nullable|integer|exists:companies,id',
            'fiscal_year' => 'nullable|string|max:16',
            'cost_center' => 'nullable|string|max:191',
            'needed_by' => 'nullable|date',
            'gst_rate' => 'nullable|numeric|min:0|max:1',
            'pst_rate' => 'nullable|numeric|min:0|max:1',
            'shipping' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:65535',
            'items' => 'required|array|min:1',
            'items.*.catalog_item_id' => 'nullable|integer|exists:catalog_items,id',
            'items.*.description' => 'required|string|max:191',
            'items.*.vendor_sku' => 'nullable|string|max:191',
            'items.*.mfr_part_number' => 'nullable|string|max:191',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.pst_applicable' => 'nullable|boolean',
            'items.*.notes' => 'nullable|string|max:191',
        ]);

        $requisition = ! empty($validated['requisition_id'])
            ? Requisition::find($validated['requisition_id'])
            : new Requisition;

        if ($requisition->exists && $requisition->status !== 'draft') {
            return redirect()->route('requisitions.show', $requisition->id)
                ->with('error', trans('admin/purchase-orders/general.requisition_locked'));
        }

        $requisition->fill([
            'title' => $validated['title'],
            'supplier_id' => $validated['supplier_id'] ?? null,
            'company_id' => $validated['company_id'] ?? null,
            'fiscal_year' => $validated['fiscal_year'] ?? null,
            'cost_center' => $validated['cost_center'] ?? null,
            'needed_by' => $validated['needed_by'] ?? null,
            'gst_rate' => $validated['gst_rate'] ?? 0.05,
            'pst_rate' => $validated['pst_rate'] ?? 0.07,
            'shipping' => $validated['shipping'] ?? 0,
            'notes' => $validated['notes'] ?? null,
        ]);

        if (! $requisition->exists) {
            $requisition->status = 'draft';
            $requisition->created_by = auth()->id();
        }

        if (! $requisition->save()) {
            return redirect()->back()->withInput()->withErrors($requisition->getErrors());
        }

        DB::transaction(function () use ($requisition, $validated) {
            $requisition->items()->delete();

            foreach (array_values($validated['items']) as $index => $line) {
                RequisitionItem::create([
                    'requisition_id' => $requisition->id,
                    'catalog_item_id' => $line['catalog_item_id'] ?? null,
                    'description' => $line['description'],
                    'vendor_sku' => $line['vendor_sku'] ?? null,
                    'mfr_part_number' => $line['mfr_part_number'] ?? null,
                    'quantity' => (int) $line['quantity'],
                    'unit_cost' => (float) $line['unit_cost'],
                    'pst_applicable' => (bool) ($line['pst_applicable'] ?? true),
                    'notes' => $line['notes'] ?? null,
                    'sort_order' => $index,
                ]);
            }
        });

        return redirect()->route('requisitions.show', $requisition->id)
            ->with('success', trans('admin/purchase-orders/general.requisition_saved'));
    }

    /**
     * Every requisition, newest first, with its running total.
     */
    public function index(Request $request)
    {
        $this->authorize('view', Requisition::class);

        $requisitions = Requisition::with('items', 'supplier', 'purchaseOrder', 'adminuser')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('requisitions/index', [
            'requisitions' => $requisitions,
            'statuses' => Requisition::STATUSES,
            'selectedStatus' => $request->query('status'),
        ]);
    }

    public function show(Requisition $requisition)
    {
        $this->authorize('view', Requisition::class);

        $requisition->load('items.catalogItem', 'supplier', 'company', 'purchaseOrder', 'adminuser');

        return view('requisitions/view', [
            'requisition' => $requisition,
            'purchaseOrders' => PurchaseOrder::orderByDesc('po_number')->pluck('po_number', 'id'),
        ]);
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
            'requisition_number' => 'nullable|string|max:191',
            'purchase_order_id' => 'nullable|integer|exists:purchase_orders,id',
            'status' => 'nullable|string|in:'.implode(',', Requisition::STATUSES),
            'notes' => 'nullable|string|max:65535',
        ]);

        $hadRequisitionNumber = (bool) $requisition->requisition_number;

        $requisition->fill([
            'requisition_number' => $validated['requisition_number'] ?? null,
            'purchase_order_id' => $validated['purchase_order_id'] ?? null,
            'notes' => $validated['notes'] ?? $requisition->notes,
        ]);

        // An explicit status wins (that's how a requisition gets submitted or
        // cancelled); otherwise the paperwork decides.
        if (! empty($validated['status'])) {
            $requisition->status = $validated['status'];
        } elseif ($requisition->purchase_order_id) {
            $requisition->status = 'ordered';
        } elseif ($requisition->requisition_number) {
            $requisition->status = 'requisitioned';
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

    /**
     * Fiscal years worth offering: the ones already in use on the purchase
     * order ledger, plus the current and next one so a new requisition can
     * be raised against a year nothing has been ordered against yet.
     *
     * ECU's fiscal year runs April to March, so April onward belongs to the
     * year that is starting.
     *
     * @return array<int, string>
     */
    private function fiscalYearOptions(): array
    {
        $years = PurchaseOrder::whereNotNull('fiscal_year')
            ->distinct()
            ->pluck('fiscal_year')
            ->all();

        $startYear = (int) now()->format('n') >= 4 ? (int) now()->format('Y') : (int) now()->format('Y') - 1;

        foreach ([$startYear, $startYear + 1] as $year) {
            $years[] = sprintf('FY%d-%s', $year, substr((string) ($year + 1), 2));
        }

        $years = array_values(array_unique(array_filter($years)));
        rsort($years);

        return $years;
    }
}
