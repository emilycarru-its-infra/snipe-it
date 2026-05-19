<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use League\Csv\EscapeFormula;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance-facing reports for the procurement module. The four key reports
 * (PO budget, invoices, capital spend, refresh forecast) render on screen
 * as a live page, with a CSV download as the secondary option. Receiving
 * and tax remain download-only.
 *
 * Each report is built once as a structured array — columns, records and
 * an optional footer — and then either rendered or streamed as CSV.
 */
class ProcurementReportsController extends Controller
{
    /**
     * Procurement dashboard: budget/spend summary cards, charts and links
     * to the individual reports.
     */
    public function index()
    {
        $this->authorize('reports.view');

        $purchaseOrders = PurchaseOrder::orderBy('po_number')->get();

        $poRows = [];
        $totalBudget = 0.0;
        $totalCommitted = 0.0;
        $totalInvoiced = 0.0;
        $committedByFy = [];

        foreach ($purchaseOrders as $po) {
            $budget = (float) $po->budget;
            $committed = $po->committedTotal();

            $totalBudget += $budget;
            $totalCommitted += $committed;
            $totalInvoiced += $po->invoicedTotal();

            $poRows[] = [
                'po_number' => $po->po_number,
                'budget' => $budget,
                'committed' => $committed,
            ];

            $fy = $po->fiscal_year ?: '—';
            $committedByFy[$fy] = ($committedByFy[$fy] ?? 0) + $committed;
        }

        // Planned (forecast) spend, grouped by the planned order's fiscal year.
        $plannedByFy = [];
        $plannedTotal = 0.0;

        foreach (Order::planned()->with('items')->get() as $order) {
            $value = (float) $order->items->sum->lineTotal();
            $plannedTotal += $value;
            $fy = $order->fiscal_year ?: '—';
            $plannedByFy[$fy] = ($plannedByFy[$fy] ?? 0) + $value;
        }

        // Invoiced totals grouped by calendar month.
        $monthly = OrderInvoice::whereNotNull('invoice_date')
            ->orderBy('invoice_date')
            ->get()
            ->groupBy(fn ($invoice) => $invoice->invoice_date->format('Y-m'))
            ->map(fn ($group) => (float) $group->sum('total'));

        // Assets reaching end-of-life within the next year.
        $eolAssets = Asset::whereNotNull('asset_eol_date')
            ->whereBetween('asset_eol_date', [now()->startOfDay(), now()->addYear()])
            ->get();

        $fiscalYears = array_keys($committedByFy + $plannedByFy);
        sort($fiscalYears);

        return view('reports/procurement', [
            'totalBudget' => $totalBudget,
            'totalCommitted' => $totalCommitted,
            'totalInvoiced' => $totalInvoiced,
            'totalRemaining' => $totalBudget - $totalCommitted,
            'plannedTotal' => $plannedTotal,
            'poCount' => $purchaseOrders->count(),
            'orderCount' => Order::actual()->count(),
            'invoiceCount' => OrderInvoice::count(),
            'eolCount' => $eolAssets->count(),
            'eolEstimate' => (float) $eolAssets->sum('purchase_cost'),
            'poRows' => $poRows,
            'fiscalYears' => array_values($fiscalYears),
            'committedByFy' => $committedByFy,
            'plannedByFy' => $plannedByFy,
            'monthlyLabels' => $monthly->keys()->all(),
            'monthlyValues' => array_values($monthly->all()),
        ]);
    }

    public function poBudget(Request $request)
    {
        $this->authorize('reports.view');

        return $this->render(
            $request,
            'po-budget-report',
            trans('admin/purchase-orders/general.report_po_budget'),
            'reports.procurement.po-budget',
            $this->poBudgetReport()
        );
    }

    public function invoices(Request $request)
    {
        $this->authorize('reports.view');

        return $this->render(
            $request,
            'invoice-reconciliation-report',
            trans('admin/purchase-orders/general.report_invoices'),
            'reports.procurement.invoices',
            $this->invoicesReport()
        );
    }

    public function capital(Request $request)
    {
        $this->authorize('reports.view');

        $forecast = $request->query('mode') === 'forecast';

        return $this->render(
            $request,
            'capital-spend-report',
            trans('admin/purchase-orders/general.report_capital'),
            'reports.procurement.capital',
            $this->capitalReport($forecast),
            $this->capitalModeToggle($forecast),
            ['mode' => $forecast ? 'forecast' : 'actual']
        );
    }

    public function refreshForecast(Request $request)
    {
        $this->authorize('reports.view');

        $report = $this->refreshForecastReport();

        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv('refresh-forecast-report', $report);
        }

        return view('reports/procurement/forecast', [
            'reportTitle' => trans('admin/purchase-orders/general.report_forecast'),
            'columns' => $report['columns'],
            'rows' => $report['records'],
            'footer' => $report['footer'] ?? null,
            'downloadUrl' => route('reports.procurement.forecast', ['format' => 'csv']),
            'canCreate' => Gate::allows('create', Order::class),
        ]);
    }

    /**
     * Generate a planned order from devices selected on the Refresh
     * Forecast report. Each selected end-of-life asset becomes a planned
     * line item carrying its replacement-cost estimate.
     */
    public function createPlannedOrder(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $validated = $request->validate([
            'assets' => 'required|array|min:1',
            'assets.*' => 'integer|exists:assets,id',
            'order_number' => 'required|string|max:191',
            'fiscal_year' => 'nullable|string|max:191',
        ]);

        // Skip any device that already has a planned replacement so the
        // forecast can't double-book the same asset.
        $alreadyPlanned = OrderItem::whereIn('replaces_asset_id', $validated['assets'])
            ->pluck('replaces_asset_id')
            ->all();

        $assets = Asset::with('model')
            ->whereIn('id', $validated['assets'])
            ->whereNotIn('id', $alreadyPlanned)
            ->get();

        if ($assets->isEmpty()) {
            return redirect()->route('reports.procurement.forecast')
                ->with('error', trans('admin/purchase-orders/general.forecast_none_selected'));
        }

        $order = new Order;
        $order->order_number = $validated['order_number'];
        $order->status = 'ordered';
        $order->is_planned = true;
        $order->fiscal_year = $validated['fiscal_year'] ?? null;
        $order->created_by = auth()->id();

        if (! $order->save()) {
            return redirect()->route('reports.procurement.forecast')
                ->withInput()->withErrors($order->getErrors());
        }

        foreach ($assets as $asset) {
            OrderItem::create([
                'order_id' => $order->id,
                'replaces_asset_id' => $asset->id,
                'description' => trans('admin/purchase-orders/general.forecast_line_description', [
                    'tag' => $asset->asset_tag,
                    'model' => $asset->model?->name ?: trans('general.na'),
                ]),
                'quantity' => 1,
                'unit_cost' => $asset->purchase_cost,
            ]);
        }

        return redirect()->route('orders.show', $order->id)
            ->with('success', trans('admin/purchase-orders/general.forecast_planned_created', ['count' => $assets->count()]));
    }

    public function receiving(): StreamedResponse
    {
        $this->authorize('reports.view');

        return $this->streamReportCsv('receiving-status-report', $this->receivingReport());
    }

    public function tax(): StreamedResponse
    {
        $this->authorize('reports.view');

        return $this->streamReportCsv('tax-summary-report', $this->taxReport());
    }

    /**
     * Per-purchase-order budget vs. spend.
     */
    private function poBudgetReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.po_number'),
            trans('admin/purchase-orders/general.title'),
            trans('admin/purchase-orders/general.fiscal_year'),
            trans('admin/purchase-orders/general.cost_center'),
            trans('general.supplier'),
            trans('admin/purchase-orders/general.status'),
            trans('admin/purchase-orders/general.budget'),
            trans('admin/purchase-orders/general.invoiced'),
            trans('admin/purchase-orders/general.committed'),
            trans('admin/purchase-orders/general.remaining'),
            trans('admin/purchase-orders/general.over_budget'),
            trans('admin/purchase-orders/general.orders'),
        ];

        $purchaseOrders = PurchaseOrder::with('supplier', 'orders.invoices', 'orders.items')
            ->orderBy('po_number')
            ->get();

        $records = [];
        $totalBudget = $totalInvoiced = $totalCommitted = $totalRemaining = $totalOrders = 0.0;

        foreach ($purchaseOrders as $po) {
            $invoiced = $po->invoicedTotal();
            $committed = $po->committedTotal();
            $remaining = $po->remaining();

            $totalBudget += (float) $po->budget;
            $totalInvoiced += $invoiced;
            $totalCommitted += $committed;
            $totalRemaining += ($remaining ?? 0);
            $totalOrders += $po->orders->count();

            $records[] = [
                'class' => $po->isOverBudget() ? 'danger' : '',
                'cells' => [
                    $po->po_number,
                    (string) $po->title,
                    (string) $po->fiscal_year,
                    (string) $po->cost_center,
                    (string) $po->supplier?->name,
                    $po->status,
                    $this->money($po->budget),
                    $this->money($invoiced),
                    $this->money($committed),
                    $remaining === null ? '' : $this->money($remaining),
                    $po->isOverBudget() ? trans('general.yes') : trans('general.no'),
                    $po->orders->count(),
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '', '', '',
            $this->money($totalBudget),
            $this->money($totalInvoiced),
            $this->money($totalCommitted),
            $this->money($totalRemaining),
            '',
            (int) $totalOrders,
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Every vendor invoice with its purchase order and order linkage.
     */
    private function invoicesReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.po_number'),
            trans('general.order_number'),
            trans('admin/orders/general.invoice_number'),
            trans('admin/orders/general.invoice_date'),
            trans('admin/orders/general.subtotal'),
            trans('admin/orders/general.tax_gst'),
            trans('admin/orders/general.tax_pst'),
            trans('admin/orders/general.shipping'),
            trans('admin/orders/general.total'),
            trans('admin/orders/general.line_items'),
        ];

        $invoices = OrderInvoice::with('order.purchaseOrder', 'items')
            ->orderBy('invoice_number')
            ->get();

        $records = [];
        $totalSubtotal = $totalGst = $totalPst = $totalShipping = $totalTotal = 0.0;

        foreach ($invoices as $invoice) {
            $totalSubtotal += (float) $invoice->subtotal;
            $totalGst += (float) $invoice->tax_gst;
            $totalPst += (float) $invoice->tax_pst;
            $totalShipping += (float) $invoice->shipping;
            $totalTotal += (float) $invoice->total;

            $records[] = [
                'class' => '',
                'cells' => [
                    (string) $invoice->order?->purchaseOrder?->po_number,
                    (string) $invoice->order?->order_number,
                    $invoice->invoice_number,
                    $this->dateString($invoice->invoice_date),
                    $this->money($invoice->subtotal),
                    $this->money($invoice->tax_gst),
                    $this->money($invoice->tax_pst),
                    $this->money($invoice->shipping),
                    $this->money($invoice->total),
                    $invoice->items->count(),
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '',
            $this->money($totalSubtotal),
            $this->money($totalGst),
            $this->money($totalPst),
            $this->money($totalShipping),
            $this->money($totalTotal),
            '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Per-order receiving progress.
     */
    private function receivingReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.po_number'),
            trans('general.order_number'),
            trans('admin/orders/general.status'),
            trans('general.supplier'),
            trans('admin/orders/general.order_date'),
            trans('admin/orders/general.line_items'),
            trans('admin/orders/general.received'),
            trans('admin/orders/general.not_received'),
        ];

        $orders = Order::actual()
            ->with('purchaseOrder', 'supplier', 'items')
            ->orderBy('order_number')
            ->get();

        $records = [];
        foreach ($orders as $order) {
            $total = $order->items->count();
            $received = $order->items->whereNotNull('received_at')->count();
            $records[] = [
                'class' => '',
                'cells' => [
                    (string) $order->purchaseOrder?->po_number,
                    $order->order_number,
                    $order->status,
                    (string) $order->supplier?->name,
                    $this->dateString($order->order_date),
                    $total,
                    $received,
                    $total - $received,
                ],
            ];
        }

        return ['columns' => $columns, 'records' => $records];
    }

    /**
     * GST / PST totals per purchase order.
     */
    private function taxReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.po_number'),
            trans('admin/purchase-orders/general.fiscal_year'),
            trans('admin/orders/general.subtotal'),
            trans('admin/orders/general.tax_gst'),
            trans('admin/orders/general.tax_pst'),
            trans('admin/orders/general.shipping'),
            trans('admin/orders/general.total'),
        ];

        $purchaseOrders = PurchaseOrder::with('orders.invoices')->orderBy('po_number')->get();

        $records = [];
        foreach ($purchaseOrders as $po) {
            $invoices = $po->orders->flatMap->invoices;
            $records[] = [
                'class' => '',
                'cells' => [
                    $po->po_number,
                    (string) $po->fiscal_year,
                    $this->money($invoices->sum('subtotal')),
                    $this->money($invoices->sum('tax_gst')),
                    $this->money($invoices->sum('tax_pst')),
                    $this->money($invoices->sum('shipping')),
                    $this->money($invoices->sum('total')),
                ],
            ];
        }

        $orphanInvoices = OrderInvoice::whereHas('order', function ($query) {
            $query->whereNull('purchase_order_id');
        })->get();

        if ($orphanInvoices->isNotEmpty()) {
            $records[] = [
                'class' => '',
                'cells' => [
                    trans('admin/purchase-orders/general.none'),
                    '',
                    $this->money($orphanInvoices->sum('subtotal')),
                    $this->money($orphanInvoices->sum('tax_gst')),
                    $this->money($orphanInvoices->sum('tax_pst')),
                    $this->money($orphanInvoices->sum('shipping')),
                    $this->money($orphanInvoices->sum('total')),
                ],
            ];
        }

        return ['columns' => $columns, 'records' => $records];
    }

    /**
     * Capital spend grouped by fiscal year and cost centre. In forecast
     * mode, planned (forecast) orders are appended grouped by fiscal year.
     */
    private function capitalReport(bool $forecast = false): array
    {
        $columns = [
            trans('admin/purchase-orders/general.fiscal_year'),
            trans('admin/purchase-orders/general.cost_center'),
            trans('admin/purchase-orders/general.purchase_orders'),
            trans('admin/purchase-orders/general.budget'),
            trans('admin/purchase-orders/general.committed'),
            trans('admin/purchase-orders/general.remaining'),
        ];

        $purchaseOrders = PurchaseOrder::with('orders.invoices', 'orders.items')->get();

        $groups = $purchaseOrders->groupBy(function ($po) {
            return ($po->fiscal_year ?: '—').'||'.($po->cost_center ?: '—');
        });

        $records = [];
        $totalBudget = $totalCommitted = $totalPlanned = 0.0;

        foreach ($groups as $key => $group) {
            [$fiscalYear, $costCenter] = explode('||', $key);
            $budget = $group->sum(fn ($po) => (float) $po->budget);
            $committed = $group->sum(fn ($po) => $po->committedTotal());
            $totalBudget += $budget;
            $totalCommitted += $committed;

            $records[] = [
                'class' => '',
                'cells' => [
                    $fiscalYear,
                    $costCenter,
                    $group->count(),
                    $this->money($budget),
                    $this->money($committed),
                    $this->money($budget - $committed),
                ],
            ];
        }

        if ($forecast) {
            $plannedGroups = Order::planned()->with('items')->get()
                ->groupBy(fn ($order) => $order->fiscal_year ?: '—');

            foreach ($plannedGroups as $fiscalYear => $group) {
                $planned = $group->sum(
                    fn ($order) => $order->items->sum(
                        fn ($item) => ((float) $item->unit_cost * (int) $item->quantity) + (float) $item->warranty_cost
                    )
                );
                $totalPlanned += $planned;

                $records[] = [
                    'class' => 'info',
                    'cells' => [
                        $fiscalYear,
                        trans('admin/orders/general.planned'),
                        $group->count(),
                        '',
                        $this->money($planned),
                        '',
                    ],
                ];
            }
        }

        $footer = [
            trans('admin/orders/general.total'), '', '',
            $this->money($totalBudget),
            $this->money($totalCommitted + $totalPlanned),
            $this->money($totalBudget - $totalCommitted - $totalPlanned),
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * The Actual / Forecast mode toggle for the Capital Spend report.
     */
    private function capitalModeToggle(bool $forecast): string
    {
        return '<div class="btn-group" role="group">'
            .'<a href="'.route('reports.procurement.capital').'" class="btn btn-sm '.($forecast ? 'btn-default' : 'btn-primary').'">'
            .e(trans('admin/purchase-orders/general.mode_actual')).'</a>'
            .'<a href="'.route('reports.procurement.capital', ['mode' => 'forecast']).'" class="btn btn-sm '.($forecast ? 'btn-primary' : 'btn-default').'">'
            .e(trans('admin/purchase-orders/general.mode_forecast')).'</a>'
            .'</div> ';
    }

    /**
     * Assets reaching end-of-life within the next year — the refresh
     * pipeline. purchase_cost stands in as the replacement-cost estimate.
     */
    private function refreshForecastReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.forecast_asset_tag'),
            trans('admin/purchase-orders/general.forecast_asset_name'),
            trans('admin/purchase-orders/general.forecast_model'),
            trans('admin/purchase-orders/general.forecast_serial'),
            trans('admin/purchase-orders/general.forecast_purchase_date'),
            trans('admin/purchase-orders/general.forecast_eol_date'),
            trans('admin/purchase-orders/general.forecast_estimate'),
            trans('admin/purchase-orders/general.forecast_status'),
            trans('general.supplier'),
        ];

        $assets = Asset::with('model', 'supplier', 'status')
            ->whereNotNull('asset_eol_date')
            ->whereBetween('asset_eol_date', [now()->startOfDay(), now()->addYear()])
            ->orderBy('asset_eol_date')
            ->get();

        // Devices that already have a planned replacement line item are
        // flagged so the forecast view can show them as planned.
        $plannedAssetIds = OrderItem::whereIn('replaces_asset_id', $assets->pluck('id'))
            ->pluck('replaces_asset_id')
            ->all();

        $records = [];
        $totalEstimate = 0.0;

        foreach ($assets as $asset) {
            $totalEstimate += (float) $asset->purchase_cost;
            $planned = in_array($asset->id, $plannedAssetIds, true);
            $records[] = [
                'class' => $planned ? 'success' : '',
                'asset_id' => $asset->id,
                'planned' => $planned,
                'cells' => [
                    (string) $asset->asset_tag,
                    (string) $asset->name,
                    (string) $asset->model?->name,
                    (string) $asset->serial,
                    $this->dateString($asset->purchase_date),
                    $this->dateString($asset->asset_eol_date),
                    $this->money($asset->purchase_cost),
                    (string) $asset->status?->name,
                    (string) $asset->supplier?->name,
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '', '', '',
            $this->money($totalEstimate),
            '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Render a report as a live page, or stream it as CSV when
     * ?format=csv is requested.
     */
    private function render(Request $request, string $filename, string $title, string $routeName, array $report, string $controls = '', array $extraParams = [])
    {
        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv($filename, $report);
        }

        return view('reports/procurement/show', [
            'reportTitle' => $title,
            'columns' => $report['columns'],
            'rows' => $report['records'],
            'footer' => $report['footer'] ?? null,
            'controls' => $controls,
            'downloadUrl' => route($routeName, array_merge(['format' => 'csv'], $extraParams)),
        ]);
    }

    /**
     * Stream a report array as a downloadable CSV with a UTF-8 BOM and
     * formula escaping.
     */
    private function streamReportCsv(string $filename, array $report): StreamedResponse
    {
        return new StreamedResponse(function () use ($report) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            $formatter = new EscapeFormula('`');

            fputcsv($handle, $report['columns']);

            foreach ($report['records'] as $record) {
                fputcsv($handle, $formatter->escapeRecord($record['cells']));
            }

            if (! empty($report['footer'])) {
                fputcsv($handle, $formatter->escapeRecord($report['footer']));
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'-'.date('Y-m-d').'.csv"',
        ]);
    }

    /**
     * Format a numeric value to two decimal places for a cell, or an
     * empty string when null.
     */
    private function money($value): string
    {
        return $value === null ? '' : number_format((float) $value, 2, '.', '');
    }

    /**
     * Format a date value for a cell. Snipe casts some asset date columns
     * to Carbon and leaves others as plain strings, so handle both.
     */
    private function dateString($value): string
    {
        if (empty($value)) {
            return '';
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }
}
