<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActionType;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Transformers\OrdersTransformer;
use App\Models\Actionlog;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Services\InvoiceApproval;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderInvoicesController extends Controller
{
    /**
     * The filters the invoices list understands, shared with its page. The
     * page's search box is `q`, so it survives the table appending its own
     * `search` to the same address; both narrow the list.
     */
    public const FILTERS = ['supplier_id', 'approval_status', 'invoice_type', 'fiscal_year', 'date_from', 'date_to', 'q'];

    /** The accounts-payable fields the payment path writes, in their API names. */
    private const PAYMENT_FIELDS = [
        'voucher_number' => 'ap_voucher_number',
        'status' => 'ap_status',
        'paid_at' => 'ap_paid_at',
        'paid_amount' => 'ap_paid_amount',
        'payment_method' => 'ap_payment_method',
        'payment_reference' => 'ap_payment_reference',
    ];

    /**
     * Every invoice across every order, for the invoices list and the
     * procurement hub. Filtered by supplier, approval status, type, fiscal
     * year and invoice-date range, and searched by invoice, order or
     * purchase-order number.
     */
    public function index(Request $request): array
    {
        $this->authorize('view', Order::class);

        $allowed = ['id', 'invoice_number', 'invoice_date', 'subtotal', 'total', 'approval_status', 'approved_at', 'invoice_type', 'created_at'];

        $query = self::filtered(OrderInvoice::query(), $request->only([...self::FILTERS, 'search']))
            ->with('order.supplier', 'order.purchaseOrder', 'purchaseOrder');

        $total = (clone $query)->count();
        $offset = ($request->input('offset') > $total) ? $total : app('api_offset_value');
        $limit = app('api_limit_value');
        $direction = $request->input('order') === 'asc' ? 'asc' : 'desc';
        $sort = in_array($request->input('sort'), $allowed, true) ? $request->input('sort') : 'invoice_date';

        $invoices = $query->orderBy('order_invoices.'.$sort, $direction)
            ->orderBy('order_invoices.id', $direction)
            ->skip($offset)->take($limit)->get();

        return (new OrdersTransformer)->transformInvoiceRows($invoices, $total);
    }

    /**
     * The list's filters applied to a query. Only the invoices the reader's
     * company may see come back; see OrderInvoice::scopeVisibleToCurrentUser().
     *
     * @param  Builder<OrderInvoice>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<OrderInvoice>
     */
    public static function filtered(Builder $query, array $filters): Builder
    {
        $query->visibleToCurrentUser();

        $value = fn (string $key) => trim((string) ($filters[$key] ?? ''));

        if ($value('supplier_id') !== '') {
            $query->whereHas('order', fn (Builder $order) => $order->where('supplier_id', (int) $value('supplier_id')));
        }
        if (in_array($value('approval_status'), OrderInvoice::APPROVAL_STATUSES, true)) {
            $value('approval_status') === 'pending'
                ? $query->where(fn (Builder $q) => $q->where('approval_status', 'pending')->orWhereNull('approval_status'))
                : $query->where('approval_status', $value('approval_status'));
        }
        if (in_array($value('invoice_type'), OrderInvoice::INVOICE_TYPES, true)) {
            $value('invoice_type') === 'regular'
                ? $query->where(fn (Builder $q) => $q->where('invoice_type', 'regular')->orWhereNull('invoice_type'))
                : $query->where('invoice_type', $value('invoice_type'));
        }
        if ($value('fiscal_year') !== '') {
            $query->forFiscalYear($value('fiscal_year'));
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if ($value($key) !== '') {
                try {
                    $query->whereDate('invoice_date', $operator, Carbon::parse($value($key))->toDateString());
                } catch (\Throwable) {
                    // An unreadable date narrows nothing rather than failing the list.
                }
            }
        }

        foreach (array_unique(array_filter([$value('q'), $value('search')])) as $search) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn (Builder $q) => $q
                ->where('invoice_number', 'like', $like)
                ->orWhere('contract_reference', 'like', $like)
                ->orWhereHas('order', fn (Builder $order) => $order
                    ->where('order_number', 'like', $like)
                    ->orWhere('vendor_order_number', 'like', $like)
                    ->orWhere('internal_order_number', 'like', $like))
                ->orWhereHas('purchaseOrder', fn (Builder $po) => $po->where('po_number', 'like', $like))
                ->orWhereHas('order.purchaseOrder', fn (Builder $po) => $po->where('po_number', 'like', $like)));
        }

        return $query;
    }

    /**
     * Approve, dispute or reopen an invoice — the Invoice Approval Queue's
     * move, for a reconciliation that has no browser. `approved_at` records
     * the day it was really paid when the approval is entered afterwards;
     * fields left out of the body are left as they are.
     */
    public function approval(Request $request, $invoiceId): JsonResponse
    {
        $this->authorize('procurement.edit');

        try {
            return $this->approve($request, $invoiceId);
        } catch (ValidationException $e) {
            // A refused approval is a client error, not a success-shaped 200.
            return response()->json(Helper::formatStandardApiResponse('error', null, $e->errors()), 422);
        }
    }

    private function approve(Request $request, $invoiceId): JsonResponse
    {
        $validated = $request->validate([
            'approval_status' => 'required|string|in:'.implode(',', InvoiceApproval::STATUSES),
            'approved_at' => 'nullable|date|before_or_equal:now',
            'is_final_invoice' => 'nullable|boolean',
            'usage_tag' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:65535',
        ]);

        $invoice = OrderInvoice::findOrFail($invoiceId);
        $approvedAt = filled($validated['approved_at'] ?? null) ? Carbon::parse($validated['approved_at']) : null;
        unset($validated['approved_at']);

        $invoice = app(InvoiceApproval::class)->apply($invoice, $request->user(), $validated, $approvedAt);

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new OrdersTransformer)->transformInvoice($invoice->fresh()),
            trans('admin/purchase-orders/general.invoice_approval_updated')
        ));
    }

    /**
     * Record where an invoice stands in accounts payable, as the finance
     * system reports it: the voucher, its status, and the payment once made.
     * Fields left out of the body are left as they are; null clears one.
     * Every call stamps the sync time and is kept on the invoice's history
     * with the old and new value of each field it changed.
     */
    public function payment(Request $request, $invoiceId): JsonResponse
    {
        $this->authorize('procurement.edit');

        try {
            $validated = $request->validate([
                'voucher_number' => 'sometimes|nullable|string|max:191',
                'status' => 'sometimes|nullable|string|in:'.implode(',', OrderInvoice::AP_STATUSES),
                'paid_at' => 'sometimes|nullable|date|before_or_equal:today',
                'paid_amount' => 'sometimes|nullable|numeric|decimal:0,2|between:-99999999.99,99999999.99',
                'payment_method' => 'sometimes|nullable|string|max:191',
                'payment_reference' => 'sometimes|nullable|string|max:191',
            ]);
        } catch (ValidationException $e) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $e->errors()), 422);
        }

        $invoice = OrderInvoice::findOrFail($invoiceId);
        abort_unless($invoice->isVisibleToCurrentUser(), 404);

        $invoice = DB::transaction(function () use ($invoice, $validated, $request) {
            $invoice = OrderInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $before = [];
            foreach (self::PAYMENT_FIELDS as $field => $column) {
                $before[$field] = $this->paymentValue($invoice, $column);
                if (array_key_exists($field, $validated)) {
                    $invoice->{$column} = $validated[$field] === '' ? null : $validated[$field];
                }
            }

            $meta = [];
            foreach (self::PAYMENT_FIELDS as $field => $column) {
                $after = $this->paymentValue($invoice, $column);
                if ($after !== $before[$field]) {
                    $meta[$column] = ['old' => $before[$field], 'new' => $after];
                }
            }

            $invoice->ap_synced_at = now();
            $invoice->save();

            $log = new Actionlog;
            $log->item_type = OrderInvoice::class;
            $log->item_id = $invoice->id;
            if ($invoice->order_id) {
                $log->target_type = Order::class;
                $log->target_id = $invoice->order_id;
            }
            $log->setAttribute('created_by', $request->user()->id);
            $log->note = $meta
                ? trans('admin/purchase-orders/general.invoice_payment_log', [
                    'invoice' => $invoice->invoice_number,
                    'fields' => implode(', ', array_keys($meta)),
                ])
                : trans('admin/purchase-orders/general.invoice_payment_log_unchanged', ['invoice' => $invoice->invoice_number]);
            $log->log_meta = $meta ? json_encode($meta) : null;
            $log->logaction(ActionType::Update);

            return $invoice;
        });

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new OrdersTransformer)->transformInvoice($invoice->fresh()),
            trans('admin/purchase-orders/general.invoice_payment_updated')
        ));
    }

    /** A payment field as a comparable string, so a log only names real changes. */
    private function paymentValue(OrderInvoice $invoice, string $column): ?string
    {
        $value = $invoice->{$column};

        return match (true) {
            $value === null => null,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $column === 'ap_paid_amount' => number_format((float) $value, 2, '.', ''),
            default => (string) $value,
        };
    }
}
