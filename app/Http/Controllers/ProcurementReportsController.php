<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\PurchaseOrder;
use Illuminate\Contracts\View\View;
use League\Csv\EscapeFormula;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance-facing reports for the procurement module — purchase orders,
 * orders, invoices and receiving — streamed as CSV for hand-off to the
 * Accounts Payable department.
 */
class ProcurementReportsController extends Controller
{
    /**
     * Landing page listing the available procurement reports.
     */
    public function index(): View
    {
        $this->authorize('reports.view');

        return view('reports/procurement');
    }

    /**
     * Per-purchase-order budget vs. spend.
     */
    public function poBudget(): StreamedResponse
    {
        $this->authorize('reports.view');

        $header = [
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

        return $this->streamCsv('po-budget-report', $header, function ($handle, $formatter) {
            $purchaseOrders = PurchaseOrder::with('supplier', 'orders.invoices', 'orders.items')
                ->orderBy('po_number')
                ->get();

            foreach ($purchaseOrders as $po) {
                $row = [
                    $po->po_number,
                    (string) $po->title,
                    (string) $po->fiscal_year,
                    (string) $po->cost_center,
                    (string) $po->supplier?->name,
                    $po->status,
                    $this->money($po->budget),
                    $this->money($po->invoicedTotal()),
                    $this->money($po->committedTotal()),
                    $this->money($po->remaining()),
                    $po->isOverBudget() ? trans('general.yes') : trans('general.no'),
                    $po->orders->count(),
                ];
                fputcsv($handle, $formatter->escapeRecord($row));
            }
        });
    }

    /**
     * Every vendor invoice with its purchase order and order linkage.
     */
    public function invoices(): StreamedResponse
    {
        $this->authorize('reports.view');

        $header = [
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

        return $this->streamCsv('invoice-reconciliation-report', $header, function ($handle, $formatter) {
            $invoices = OrderInvoice::with('order.purchaseOrder', 'items')
                ->orderBy('invoice_number')
                ->get();

            foreach ($invoices as $invoice) {
                $row = [
                    (string) $invoice->order?->purchaseOrder?->po_number,
                    (string) $invoice->order?->order_number,
                    $invoice->invoice_number,
                    $invoice->invoice_date ? $invoice->invoice_date->format('Y-m-d') : '',
                    $this->money($invoice->subtotal),
                    $this->money($invoice->tax_gst),
                    $this->money($invoice->tax_pst),
                    $this->money($invoice->shipping),
                    $this->money($invoice->total),
                    $invoice->items->count(),
                ];
                fputcsv($handle, $formatter->escapeRecord($row));
            }
        });
    }

    /**
     * Per-order receiving progress — what has arrived and what is still
     * outstanding.
     */
    public function receiving(): StreamedResponse
    {
        $this->authorize('reports.view');

        $header = [
            trans('admin/purchase-orders/general.po_number'),
            trans('general.order_number'),
            trans('admin/orders/general.status'),
            trans('general.supplier'),
            trans('admin/orders/general.order_date'),
            trans('admin/orders/general.line_items'),
            trans('admin/orders/general.received'),
            trans('admin/orders/general.not_received'),
        ];

        return $this->streamCsv('receiving-status-report', $header, function ($handle, $formatter) {
            $orders = Order::with('purchaseOrder', 'supplier', 'items')
                ->orderBy('order_number')
                ->get();

            foreach ($orders as $order) {
                $total = $order->items->count();
                $received = $order->items->whereNotNull('received_at')->count();
                $row = [
                    (string) $order->purchaseOrder?->po_number,
                    $order->order_number,
                    $order->status,
                    (string) $order->supplier?->name,
                    $order->order_date ? $order->order_date->format('Y-m-d') : '',
                    $total,
                    $received,
                    $total - $received,
                ];
                fputcsv($handle, $formatter->escapeRecord($row));
            }
        });
    }

    /**
     * GST / PST totals per purchase order, for AP cash-flow planning.
     */
    public function tax(): StreamedResponse
    {
        $this->authorize('reports.view');

        $header = [
            trans('admin/purchase-orders/general.po_number'),
            trans('admin/purchase-orders/general.fiscal_year'),
            trans('admin/orders/general.subtotal'),
            trans('admin/orders/general.tax_gst'),
            trans('admin/orders/general.tax_pst'),
            trans('admin/orders/general.shipping'),
            trans('admin/orders/general.total'),
        ];

        return $this->streamCsv('tax-summary-report', $header, function ($handle, $formatter) {
            $purchaseOrders = PurchaseOrder::with('orders.invoices')->orderBy('po_number')->get();

            foreach ($purchaseOrders as $po) {
                $invoices = $po->orders->flatMap->invoices;
                $row = [
                    $po->po_number,
                    (string) $po->fiscal_year,
                    $this->money($invoices->sum('subtotal')),
                    $this->money($invoices->sum('tax_gst')),
                    $this->money($invoices->sum('tax_pst')),
                    $this->money($invoices->sum('shipping')),
                    $this->money($invoices->sum('total')),
                ];
                fputcsv($handle, $formatter->escapeRecord($row));
            }

            // Invoices on orders that aren't tied to a purchase order.
            $orphanInvoices = OrderInvoice::whereHas('order', function ($query) {
                $query->whereNull('purchase_order_id');
            })->get();

            if ($orphanInvoices->isNotEmpty()) {
                $row = [
                    trans('admin/purchase-orders/general.none'),
                    '',
                    $this->money($orphanInvoices->sum('subtotal')),
                    $this->money($orphanInvoices->sum('tax_gst')),
                    $this->money($orphanInvoices->sum('tax_pst')),
                    $this->money($orphanInvoices->sum('shipping')),
                    $this->money($orphanInvoices->sum('total')),
                ];
                fputcsv($handle, $formatter->escapeRecord($row));
            }
        });
    }

    /**
     * Capital spend grouped by fiscal year and cost centre.
     */
    public function capital(): StreamedResponse
    {
        $this->authorize('reports.view');

        $header = [
            trans('admin/purchase-orders/general.fiscal_year'),
            trans('admin/purchase-orders/general.cost_center'),
            trans('admin/purchase-orders/general.purchase_orders'),
            trans('admin/purchase-orders/general.budget'),
            trans('admin/purchase-orders/general.committed'),
            trans('admin/purchase-orders/general.remaining'),
        ];

        return $this->streamCsv('capital-spend-report', $header, function ($handle, $formatter) {
            $purchaseOrders = PurchaseOrder::with('orders.invoices', 'orders.items')->get();

            $groups = $purchaseOrders->groupBy(function ($po) {
                return ($po->fiscal_year ?: '—').'||'.($po->cost_center ?: '—');
            });

            foreach ($groups as $key => $group) {
                [$fiscalYear, $costCenter] = explode('||', $key);
                $budget = $group->sum(fn ($po) => (float) $po->budget);
                $committed = $group->sum(fn ($po) => $po->committedTotal());
                $row = [
                    $fiscalYear,
                    $costCenter,
                    $group->count(),
                    $this->money($budget),
                    $this->money($committed),
                    $this->money($budget - $committed),
                ];
                fputcsv($handle, $formatter->escapeRecord($row));
            }
        });
    }

    /**
     * Assets reaching end-of-life within the next year — the refresh
     * pipeline. purchase_cost stands in as the replacement-cost estimate.
     */
    public function refreshForecast(): StreamedResponse
    {
        $this->authorize('reports.view');

        $header = [
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

        return $this->streamCsv('refresh-forecast-report', $header, function ($handle, $formatter) {
            $assets = Asset::with('model', 'supplier', 'status')
                ->whereNotNull('asset_eol_date')
                ->whereBetween('asset_eol_date', [now()->startOfDay(), now()->addYear()])
                ->orderBy('asset_eol_date')
                ->get();

            foreach ($assets as $asset) {
                $row = [
                    (string) $asset->asset_tag,
                    (string) $asset->name,
                    (string) $asset->model?->name,
                    (string) $asset->serial,
                    $asset->purchase_date ? $asset->purchase_date->format('Y-m-d') : '',
                    $asset->asset_eol_date ? $asset->asset_eol_date->format('Y-m-d') : '',
                    $this->money($asset->purchase_cost),
                    (string) $asset->status?->name,
                    (string) $asset->supplier?->name,
                ];
                fputcsv($handle, $formatter->escapeRecord($row));
            }
        });
    }

    /**
     * Format a numeric value to two decimal places for a CSV cell, or an
     * empty string when null.
     */
    private function money($value): string
    {
        return $value === null ? '' : number_format((float) $value, 2, '.', '');
    }

    /**
     * Stream rows as a downloadable CSV with a UTF-8 BOM and formula
     * escaping, following the convention used by the other reports.
     */
    private function streamCsv(string $filename, array $header, \Closure $writeRows): StreamedResponse
    {
        return new StreamedResponse(function () use ($header, $writeRows) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            $formatter = new EscapeFormula('`');
            fputcsv($handle, $header);
            $writeRows($handle, $formatter);
            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'-'.date('Y-m-d').'.csv"',
        ]);
    }
}
