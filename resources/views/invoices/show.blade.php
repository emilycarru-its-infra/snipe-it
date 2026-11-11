@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/purchase-orders/general.invoice_page_title', ['number' => $invoice->invoice_number]) }}
    @parent
@stop

{{-- Page content --}}
@section('content')
@php
    $money = fn ($value) => $value !== null ? Helper::formatCurrencyOutput($value) : '—';
    $date = fn ($value) => $value ? $value->format('Y-m-d') : '—';
    $status = $invoice->approval_status ?: 'pending';
    $statusClass = ['pending' => 'label-warning', 'approved' => 'label-success', 'disputed' => 'label-danger'][$status] ?? 'label-default';
    $type = $invoice->invoice_type ?: 'regular';
@endphp
<style>
    .invoice-facts {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px 18px;
        margin-bottom: 4px;
    }
    @media (max-width: 991px) { .invoice-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 480px) { .invoice-facts { grid-template-columns: minmax(0, 1fr); } }
    .invoice-facts .invoice-fact-label {
        display: block;
        font-size: 12px;
        color: var(--text-muted, #777);
        margin-bottom: 2px;
    }
    .invoice-facts .invoice-fact-value { font-size: 14px; overflow-wrap: anywhere; }
    .invoice-facts .invoice-fact-wide { grid-column: 1 / -1; }
    .invoice-totals td, .invoice-totals th { padding: 4px 8px !important; }
    .invoice-lines-wrap { overflow-x: auto; }
</style>
<div class="row">
    <div class="col-lg-10 col-lg-offset-1 col-md-12 col-sm-12 col-sm-offset-0">

        {{-- Header: who billed it, against what, and the totals breakdown. --}}
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">
                    <i class="fas fa-file-invoice-dollar" aria-hidden="true"></i> {{ $invoice->invoice_number }}
                    <span class="label {{ $statusClass }}">{{ trans('admin/purchase-orders/general.invoice_approval_'.$status) }}</span>
                    @if ($type !== 'regular')
                        <span class="label label-info">{{ trans('admin/purchase-orders/general.invoice_type_'.$type) }}</span>
                    @endif
                </h2>
                <div class="pull-right">
                    @if ($order)
                        <a href="{{ route('orders.show', $order) }}" class="btn btn-sm btn-default">
                            <x-icon type="order" /> {{ $order->primaryNumber() }}
                        </a>
                    @endif
                    <a href="{{ route('invoices.index') }}" class="btn btn-sm btn-default">
                        {{ trans('admin/orders/general.invoices') }}
                    </a>
                </div>
            </div>
            <div class="box-body">
                <div class="row">
                    <div class="col-md-8">
                        <div class="invoice-facts">
                            <div>
                                <span class="invoice-fact-label">{{ trans('admin/orders/general.invoice_date') }}</span>
                                <span class="invoice-fact-value">{{ $date($invoice->invoice_date) }}</span>
                            </div>
                            <div>
                                <span class="invoice-fact-label">{{ trans('general.supplier') }}</span>
                                <span class="invoice-fact-value">{{ $order?->supplier?->name ?: '—' }}</span>
                            </div>
                            <div>
                                <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_type') }}</span>
                                <span class="invoice-fact-value">{{ trans('admin/purchase-orders/general.invoice_type_'.$type) }}</span>
                            </div>
                            <div>
                                <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.attestation_type') }}</span>
                                <span class="invoice-fact-value">{{ trans('admin/purchase-orders/general.attestation_'.($invoice->attestation_type ?: 'vendor_invoice')) }}</span>
                            </div>
                            @if ($invoice->contract_reference)
                                <div>
                                    <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.lease_contract_id') }}</span>
                                    <span class="invoice-fact-value">{{ $invoice->contract_reference }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="col-md-4">
                        <table class="table table-condensed invoice-totals" aria-label="{{ trans('admin/purchase-orders/general.invoice_page_totals') }}">
                            <tbody>
                                <tr><th scope="row">{{ trans('admin/orders/general.subtotal') }}</th><td class="text-right text-nowrap">{{ $money($invoice->subtotal) }}</td></tr>
                                <tr><th scope="row">{{ trans('admin/orders/general.tax_gst') }}</th><td class="text-right text-nowrap">{{ $money($invoice->tax_gst) }}</td></tr>
                                <tr><th scope="row">{{ trans('admin/orders/general.tax_pst') }}</th><td class="text-right text-nowrap">{{ $money($invoice->tax_pst) }}</td></tr>
                                <tr><th scope="row">{{ trans('admin/orders/general.shipping') }}</th><td class="text-right text-nowrap">{{ $money($invoice->shipping) }}</td></tr>
                                <tr><th scope="row">{{ trans('admin/orders/general.total') }}</th><td class="text-right text-nowrap"><strong>{{ $money($invoice->total) }}</strong></td></tr>
                            </tbody>
                        </table>
                        @if ($componentsDifference !== null && abs($componentsDifference) >= 0.01)
                            <p class="text-warning">
                                <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                                {{ trans('admin/purchase-orders/general.invoice_page_components_mismatch', [
                                    'sum' => $money((float) $invoice->total - $componentsDifference),
                                    'total' => $money($invoice->total),
                                    'difference' => $money($componentsDifference),
                                ]) }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- The order and purchase order it hangs off, and what the PO has left. --}}
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.invoice_page_links') }}</h3>
            </div>
            <div class="box-body">
                <div class="invoice-facts">
                    <div>
                        <span class="invoice-fact-label">{{ trans('admin/orders/general.order') }}</span>
                        <span class="invoice-fact-value">
                            @if ($order)
                                <a href="{{ route('orders.show', $order) }}">{{ $order->primaryNumber() }}</a>
                                @if ($order->secondaryNumber())
                                    <small class="text-muted">{{ $order->secondaryNumber() }}</small>
                                @endif
                            @else
                                —
                            @endif
                        </span>
                    </div>
                    <div>
                        <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.purchase_order') }}</span>
                        <span class="invoice-fact-value">
                            @if ($purchaseOrder)
                                <a href="{{ route('purchase-orders.show', $purchaseOrder) }}">{{ $purchaseOrder->po_number }}</a>
                            @else
                                —
                            @endif
                        </span>
                    </div>
                    @if ($purchaseOrder)
                        <div>
                            <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_page_po_budget') }}</span>
                            <span class="invoice-fact-value">{{ $purchaseOrder->budget !== null ? $money($purchaseOrder->budget) : trans('admin/purchase-orders/general.invoice_page_no_budget') }}</span>
                        </div>
                        <div>
                            <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_page_po_invoiced') }}</span>
                            <span class="invoice-fact-value">{{ $money($poInvoiced) }}</span>
                        </div>
                        @if ($poRemaining !== null)
                            <div>
                                <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_page_po_remaining') }}</span>
                                <span class="invoice-fact-value {{ $poRemaining < 0 ? 'text-danger' : '' }}">{{ $money($poRemaining) }}</span>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        {{-- The lines billed on it, and whether they add up. --}}
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.invoice_page_lines') }}</h3>
            </div>
            <div class="box-body">
                @if ($invoice->isAdjustment())
                    <p class="text-muted">{{ trans('admin/purchase-orders/general.invoice_page_lines_adjustment', ['type' => trans('admin/purchase-orders/general.invoice_type_'.$type)]) }}</p>
                @elseif ($linesDifference !== null && abs($linesDifference) >= 0.01)
                    <p class="text-danger">
                        <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                        {{ trans('admin/purchase-orders/general.invoice_page_lines_mismatch', [
                            'lines' => $money($linesTotal),
                            'subtotal' => $money($invoice->subtotal),
                            'difference' => $money($linesDifference),
                        ]) }}
                    </p>
                @elseif ($invoice->items->isNotEmpty())
                    <p class="text-success"><i class="fas fa-check-circle" aria-hidden="true"></i> {{ trans('admin/purchase-orders/general.invoice_page_lines_match') }}</p>
                @endif

                <div class="invoice-lines-wrap">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('admin/orders/general.item') }}</th>
                            <th>{{ trans('admin/orders/general.part_numbers') }}</th>
                            <th class="text-right">{{ trans('admin/orders/general.qty') }}</th>
                            <th class="text-right">{{ trans('admin/orders/general.unit_cost') }}</th>
                            <th class="text-right">{{ trans('admin/orders/general.warranty_cost') }}</th>
                            <th class="text-right">{{ trans('admin/purchase-orders/general.invoice_page_lines_total') }}</th>
                            <th>{{ trans('admin/orders/general.received') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($invoice->items as $lineItem)
                        @php
                            $li = $lineItem->item;
                            $itemName = $li ? ($lineItem->item_type === \App\Models\Asset::class ? $li->present()->fullName() : ($li->name ?? '')) : '';
                        @endphp
                        <tr>
                            <td>
                                @if ($lineItem->item_type)
                                    <span class="text-muted small" style="display:block;text-transform:uppercase;">{{ $lineItem->itemTypeLabel() }}</span>
                                @endif
                                @if ($li && $lineItem->item_type === \App\Models\Asset::class)
                                    <x-icon type="asset" />
                                    <a href="{{ route('hardware.show', $li) }}">{{ $itemName }}</a>
                                @elseif ($li)
                                    {!! $li->present()->nameUrl() !!}
                                @else
                                    {{ $lineItem->description ?: '—' }}
                                @endif
                                @if ($li && filled($lineItem->description) && ! str_contains(mb_strtolower($itemName), mb_strtolower($lineItem->description)))
                                    <span class="text-muted small" style="display:block;">{{ $lineItem->description }}</span>
                                @endif
                            </td>
                            <td class="small">
                                {{ collect([$lineItem->mfr_part_number, $lineItem->vendor_sku])->filter()->implode(' · ') ?: '—' }}
                            </td>
                            <td class="text-right">{{ $lineItem->quantity }}</td>
                            <td class="text-right text-nowrap">{{ $money($lineItem->unit_cost) }}</td>
                            <td class="text-right text-nowrap">{{ (float) $lineItem->warranty_cost > 0 ? $money($lineItem->warranty_cost) : '' }}</td>
                            <td class="text-right text-nowrap">{{ $money($lineItem->lineTotal()) }}</td>
                            <td class="text-nowrap">
                                @if ($lineItem->received_at)
                                    <span class="text-success"><i class="fas fa-check-circle" aria-hidden="true"></i> {{ $lineItem->received_at->format('Y-m-d') }}</span>
                                @else
                                    <span class="text-muted">{{ trans('admin/orders/general.not_received') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">{{ trans('admin/purchase-orders/general.invoice_page_no_lines') }}</td></tr>
                    @endforelse
                    </tbody>
                    @if ($invoice->items->isNotEmpty())
                        <tfoot>
                            <tr>
                                <th colspan="5" class="text-right">{{ trans('admin/purchase-orders/general.invoice_page_lines_total') }}</th>
                                <th class="text-right text-nowrap">{{ $money($linesTotal) }}</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    @endif
                </table>
                </div>
            </div>
        </div>

        <div class="row">
            {{-- Approval, with the approval queue's own action. --}}
            <div class="col-md-6">
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">{{ trans('admin/purchase-orders/general.invoice_page_approval') }}</h3>
                    </div>
                    <div class="box-body">
                        <div class="invoice-facts" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                            <div>
                                <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_approval_status') }}</span>
                                <span class="invoice-fact-value"><span class="label {{ $statusClass }}">{{ trans('admin/purchase-orders/general.invoice_approval_'.$status) }}</span></span>
                            </div>
                            <div>
                                <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_approved_at') }}</span>
                                <span class="invoice-fact-value">{{ $invoice->approved_at ? Helper::getFormattedDateObject($invoice->approved_at, 'datetime', false) : '—' }}</span>
                            </div>
                            <div>
                                <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_approver') }}</span>
                                <span class="invoice-fact-value">{{ $invoice->approver?->present()->fullName ?: '—' }}</span>
                            </div>
                            <div>
                                <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_final') }}</span>
                                <span class="invoice-fact-value">{{ $invoice->is_final_invoice ? trans('general.yes') : trans('general.no') }}</span>
                            </div>
                            <div class="invoice-fact-wide">
                                <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_usage') }}</span>
                                <span class="invoice-fact-value">{{ $invoice->usage_tag ?: '—' }}</span>
                            </div>
                        </div>

                        @can('procurement.edit')
                            <hr>
                            @if ($errors->has('approval_status'))
                                <p class="text-danger">{{ $errors->first('approval_status') }}</p>
                            @endif
                            {{-- The queue reads an unticked box as "not final", so the
                                 current values ride along with every press. --}}
                            <form method="post" action="{{ route('reports.procurement.invoice-approval.update', $invoice->id) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="return_to" value="invoice">
                                <div class="form-group">
                                    <label for="invoice-usage-tag">{{ trans('admin/purchase-orders/general.invoice_usage') }}</label>
                                    <input type="text" class="form-control input-sm" id="invoice-usage-tag" name="usage_tag" value="{{ old('usage_tag', $invoice->usage_tag) }}" maxlength="191">
                                </div>
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="is_final_invoice" value="1" @checked(old('is_final_invoice', $invoice->is_final_invoice))>
                                        {{ trans('admin/purchase-orders/general.invoice_final') }}
                                    </label>
                                </div>
                                @if ($status !== 'approved')
                                    <button type="submit" name="approval_status" value="approved" class="btn btn-success btn-sm">
                                        {{ trans('admin/purchase-orders/general.invoice_action_approve') }}
                                    </button>
                                @endif
                                @if ($status !== 'disputed')
                                    <button type="submit" name="approval_status" value="disputed" class="btn btn-default btn-sm">
                                        {{ trans('admin/purchase-orders/general.invoice_action_dispute') }}
                                    </button>
                                @endif
                                @if ($status !== 'pending')
                                    <button type="submit" name="approval_status" value="pending" class="btn btn-default btn-sm">
                                        {{ trans('admin/purchase-orders/general.invoice_approval_pending') }}
                                    </button>
                                @endif
                            </form>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- OK to pay: whether the lessor has been told, and the delivery record. --}}
            <div class="col-md-6">
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">{{ trans('admin/purchase-orders/general.invoice_page_okp') }}</h3>
                        @can('procurement.edit')
                            <div class="box-tools pull-right">
                                <a href="{{ route('reports.procurement.invoice-acceptance', $invoice) }}" class="btn btn-sm btn-default">{{ trans('admin/purchase-orders/general.okp_acceptance_title') }}</a>
                            </div>
                        @endcan
                    </div>
                    <div class="box-body">
                        @if ($okpLabel !== '')
                            <p>{{ $okpLabel }}</p>
                        @else
                            <p class="text-muted">{{ trans('admin/purchase-orders/general.invoice_page_okp_none') }}</p>
                        @endif
                        @if ($invoice->okayToPayDelivery)
                            <p class="small">
                                <strong>{{ trans('admin/purchase-orders/general.invoice_page_okp_delivery') }}:</strong>
                                {{ trans('admin/purchase-orders/general.okp_delivery_record', [
                                    'status' => $invoice->okayToPayDelivery->status,
                                    'time' => Helper::getFormattedDateObject($invoice->okayToPayDelivery->claimed_at, 'datetime', false),
                                ]) }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Accounts payable, as the finance system last reported it. --}}
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.invoice_page_payment') }}</h3>
            </div>
            <div class="box-body">
                @if (! $invoice->hasPaymentRecord())
                    <p class="text-muted">{{ trans('admin/purchase-orders/general.invoice_page_payment_none') }}</p>
                @else
                    @if ($paymentDifference !== null && abs($paymentDifference) >= 0.01)
                        <p class="text-danger">
                            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                            {{ trans('admin/purchase-orders/general.invoice_page_payment_mismatch', [
                                'paid' => $money($invoice->ap_paid_amount),
                                'total' => $money($invoice->total),
                                'difference' => $money($paymentDifference),
                            ]) }}
                        </p>
                    @endif
                    <div class="invoice-facts">
                        <div>
                            <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_page_payment_voucher') }}</span>
                            <span class="invoice-fact-value">{{ $invoice->ap_voucher_number ?: '—' }}</span>
                        </div>
                        <div>
                            <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_page_payment_status') }}</span>
                            <span class="invoice-fact-value">{{ $invoice->ap_status ? trans('admin/purchase-orders/general.ap_status_'.$invoice->ap_status) : '—' }}</span>
                        </div>
                        <div>
                            <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_page_payment_paid_at') }}</span>
                            <span class="invoice-fact-value">{{ $date($invoice->ap_paid_at) }}</span>
                        </div>
                        <div>
                            <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_page_payment_paid_amount') }}</span>
                            <span class="invoice-fact-value">{{ $money($invoice->ap_paid_amount) }}</span>
                        </div>
                        <div>
                            <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_page_payment_method') }}</span>
                            <span class="invoice-fact-value">{{ $invoice->ap_payment_method ?: '—' }}</span>
                        </div>
                        <div>
                            <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_page_payment_reference') }}</span>
                            <span class="invoice-fact-value">{{ $invoice->ap_payment_reference ?: '—' }}</span>
                        </div>
                        <div>
                            <span class="invoice-fact-label">{{ trans('admin/purchase-orders/general.invoice_page_payment_synced_at') }}</span>
                            <span class="invoice-fact-value">{{ $invoice->ap_synced_at ? Helper::getFormattedDateObject($invoice->ap_synced_at, 'datetime', false) : '—' }}</span>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @if ($invoice->notes)
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('general.notes') }}</h3>
                </div>
                <div class="box-body">{!! nl2br(e($invoice->notes)) !!}</div>
            </div>
        @endif

        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.invoice_page_history') }}</h3>
            </div>
            <div class="box-body">
                <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('general.date') }}</th>
                            <th>{{ trans('general.admin') }}</th>
                            <th>{{ trans('general.action') }}</th>
                            <th>{{ trans('general.notes') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($history as $entry)
                        <tr>
                            <td class="text-nowrap">{{ Helper::getFormattedDateObject($entry->created_at, 'datetime', false) }}</td>
                            <td>{{ $entry->adminuser?->present()->fullName ?: '—' }}</td>
                            <td>{{ $entry->action_type }}</td>
                            <td>{{ $entry->note }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">{{ trans('admin/purchase-orders/general.invoice_page_no_history') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>
@stop
