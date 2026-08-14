@extends('layouts/default')

@section('title')
    {{ trans('admin/orders/general.orders') }}
    @parent
@stop

{{-- Every order on one page, walkable without clicking into each: native
     <details> accordions, so expand/collapse costs no JavaScript and the
     browser's find-in-page still works across collapsed content.

     Above the list, allocation: hardware that arrived without a request
     (extras on a batch, or a shipment whose reference CDW did not carry)
     paired to the store requests still waiting — the manual form of the
     webhook's automatic claim, with a human choosing instead of FIFO. --}}

@section('content')

<style>
.ord-filters { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
/* Card skin and tags come from the shared ECU design layer; the accordion
   keeps its tighter radius and spacing locally. */
.ord-card { border-radius: 10px; margin-bottom: 8px; }
.ord-card > summary { list-style: none; cursor: pointer; padding: 10px 14px; display: flex; gap: 14px;
    align-items: baseline; flex-wrap: wrap; }
.ord-card > summary::-webkit-details-marker { display: none; }
.ord-card > summary::before { content: '▸'; opacity: .5; }
.ord-card[open] > summary::before { content: '▾'; }
.ord-num { font-weight: 700; min-width: 90px; }
.ord-meta { opacity: .65; font-size: 12px; }
.ord-body { padding: 4px 16px 14px 34px; }
.ord-items { width: 100%; font-size: 13px; margin-top: 4px; }
.ord-items td, .ord-items th { padding: 4px 8px 4px 0; text-align: left; }
.ord-items th { opacity: .55; font-weight: 600; font-size: 11px; text-transform: uppercase; }
.ord-alloc { border: 1px solid light-dark(#f0ad4e, #8a6d3b); border-radius: 10px; padding: 14px 16px;
    margin-bottom: 18px; background: light-dark(#fcf8f0, #2a2620); }
.ord-alloc-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 6px 0;
    border-top: 1px solid light-dark(#eee3cc, #3a3427); }
.ord-alloc-row:first-of-type { border-top: 0; }

</style>

@if ($arrivals->isNotEmpty())
    <div class="ord-alloc">
        <h4 style="margin:0 0 4px;">{{ trans('admin/orders/general.allocation_heading') }}
            <span class="badge">{{ $arrivals->count() }}</span></h4>
        <p class="ord-meta" style="margin:0 0 8px;">{{ trans('admin/orders/general.allocation_intro') }}</p>

        @foreach ($arrivals as $arrival)
            @php $candidates = $waiting->where('model_id', $arrival->model_id); @endphp
            <div class="ord-alloc-row">
                <span class="ecu-tag">{{ $arrival->asset_tag }}</span>
                <span>{{ $arrival->model->name ?? '' }}</span>
                <span class="ecu-tag ord-meta">{{ $arrival->serial }}</span>
                @if ($arrival->order_number)
                    <span class="ord-meta">{{ trans('admin/orders/general.allocation_from_order') }} {{ $arrival->order_number }}</span>
                @endif

                @if ($candidates->isEmpty())
                    <span class="ord-meta" style="margin-left:auto;">{{ trans('admin/orders/general.allocation_none_waiting') }}</span>
                @else
                    <form method="POST" action="{{ route('orders.allocate') }}" class="form-inline" style="margin-left:auto;">
                        {{ csrf_field() }}
                        <input type="hidden" name="arrival_id" value="{{ $arrival->id }}">
                        <select name="waiting_id" class="form-control input-sm">
                            {{-- Oldest request first — the default is the FIFO
                                 answer; the dropdown is the human override. --}}
                            @foreach ($candidates as $candidate)
                                <option value="{{ $candidate->id }}">
                                    {{ $candidate->asset_tag }}
                                    · {{ $candidate->name ?: trans('general.na') }}
                                    · {{ $candidate->order_number }}
                                    · {{ trans('admin/orders/general.waiting_since') }} {{ $candidate->created_at->format('M j') }}
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-sm btn-warning">{{ trans('admin/orders/general.allocate_button') }}</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
@endif

<div class="ord-filters">
    @foreach (array_merge(['all'], \App\Models\Order::STATUSES) as $status)
        <a href="{{ route('orders.index', array_filter(['status' => $status === 'all' ? null : $status, 'needs_allocation' => $needsAllocation ?: null])) }}"
           class="btn btn-sm {{ $selectedStatus === $status ? 'btn-primary' : 'btn-default' }}">
            {{ $status === 'all' ? trans('admin/orders/general.filter_all') : trans('admin/orders/general.status_'.$status) }}
        </a>
    @endforeach
    <a href="{{ route('orders.index', array_filter(['status' => $selectedStatus === 'all' ? null : $selectedStatus, 'needs_allocation' => $needsAllocation ? null : 1])) }}"
       class="btn btn-sm {{ $needsAllocation ? 'btn-warning' : 'btn-default' }}">
        {{ trans('admin/orders/general.filter_needs_allocation') }}
    </a>
    <span style="margin-left:auto;"></span>
    <button type="button" class="btn btn-sm btn-default" onclick="document.querySelectorAll('details.ord-card').forEach(d => d.open = true)">
        {{ trans('admin/orders/general.expand_all') }}
    </button>
    <button type="button" class="btn btn-sm btn-default" onclick="document.querySelectorAll('details.ord-card').forEach(d => d.open = false)">
        {{ trans('admin/orders/general.collapse_all') }}
    </button>
    @can('create', \App\Models\Order::class)
        <a href="{{ route('orders.create') }}" class="btn btn-sm btn-primary">{{ trans('general.create') }}</a>
    @endcan
</div>

@foreach ($orders as $order)
    @php
        $received = $order->items->filter->isReceived()->count();
        $total = $order->items->count();
        $labelClass = ['ordered' => 'label-info', 'shipped' => 'label-primary',
                       'partially_received' => 'label-warning', 'received' => 'label-success',
                       'cancelled' => 'label-default'][$order->status] ?? 'label-default';
    @endphp
    <details class="ecu-card ord-card">
        <summary>
            <span class="ord-num">{{ $order->order_number }}</span>
            <span class="label {{ $labelClass }}">{{ trans('admin/orders/general.status_'.$order->status) }}</span>
            @if ($total)
                <span class="ord-meta">{{ trans('admin/orders/general.received_progress', ['received' => $received, 'total' => $total]) }}</span>
            @endif
            <span class="ord-meta">{{ $order->supplier->name ?? '' }}</span>
            <span class="ord-meta">{{ $order->order_date?->format('Y-m-d') ?? $order->created_at->format('Y-m-d') }}</span>
            <span class="ord-meta" style="margin-left:auto;">${{ \App\Helpers\Helper::formatCurrencyOutput($order->order_cost) }}</span>
        </summary>
        <div class="ord-body">
            <table class="ord-items">
                <thead><tr>
                    <th>{{ trans('admin/orders/general.item') }}</th>
                    <th>{{ trans('general.qty') }}</th>
                    <th>{{ trans('admin/orders/general.unit_cost') }}</th>
                    <th>{{ trans('admin/orders/general.received') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($order->items as $line)
                    <tr>
                        <td>
                            @if ($line->item_type === \App\Models\Asset::class && $line->item)
                                <a class="js-lightbox" href="{{ route('hardware.show', $line->item_id) }}">
                                    {{ $line->item->asset_tag }} — {{ $line->item->name ?: ($line->item->model->name ?? '') }}
                                </a>
                            @else
                                {{ $line->description ?: trans('general.na') }}
                            @endif
                        </td>
                        <td>{{ $line->quantity }}</td>
                        <td style="white-space:nowrap;">${{ \App\Helpers\Helper::formatCurrencyOutput($line->unit_cost) }}</td>
                        <td>{{ $line->received_at?->format('Y-m-d') ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if ($order->shipments->isNotEmpty() || $order->invoices->isNotEmpty())
                <p class="ord-meta" style="margin:8px 0 0;">
                    @foreach ($order->shipments as $shipment)
                        {{ trans('admin/orders/general.shipment') }} <span class="ecu-tag">{{ $shipment->tracking_number }}</span>@if(!$loop->last) · @endif
                    @endforeach
                    @if ($order->shipments->isNotEmpty() && $order->invoices->isNotEmpty()) &nbsp;|&nbsp; @endif
                    @foreach ($order->invoices as $invoice)
                        {{ trans('admin/orders/general.invoice_number') }} {{ $invoice->invoice_number }}@if(!$loop->last) · @endif
                    @endforeach
                </p>
            @endif

            <p style="margin:10px 0 0;">
                <a class="js-lightbox" href="{{ route('orders.show', $order->id) }}" class="btn btn-xs btn-default">{{ trans('admin/orders/general.open_order') }}</a>
            </p>
        </div>
    </details>
@endforeach

@if ($orders->isEmpty())
    <div class="box box-default"><div class="box-body">
        <p class="text-muted">{{ trans('general.no_results') }}</p>
    </div></div>
@endif

{{ $orders->links() }}

@stop
