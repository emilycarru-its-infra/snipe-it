@extends('layouts/default')

@section('title')
    {{ trans('admin/store/general.status_page_title') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('store.index') }}" class="btn btn-sm btn-primary">
        {{ trans('admin/store/general.store') }}
    </a>
@stop

{{-- The order status page — the link a requester gets in email, and the
     answer to "where is my laptop" without asking IT.

     For a faculty refresh it reads as a handover: the machine going out on
     the left, the machine coming in on the right, each with its own facts.
     A first machine keeps the same two-column shape with the left side
     saying so — the layout stays constant so the page reads the same way
     for everyone.

     The right side carries the asset tag from the minute the order was
     placed, because the asset is provisioned at submission; the serial
     appears when CDW ships. --}}

@section('content')

<style>
.sto-split { display: flex; gap: 18px; flex-wrap: wrap; }
.sto-pane { flex: 1 1 340px; border: 1px solid light-dark(#e2e2e6, #3a3a3e); border-radius: 14px; padding: 20px; background: light-dark(#fff, #1f2023); }
.sto-pane-muted { background: light-dark(#fafafc, #212226); }
.sto-kicker { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; opacity: .55; margin-bottom: 6px; }
.sto-name { font-size: 19px; font-weight: 700; margin: 0 0 2px; }
.sto-sub { font-size: 13px; opacity: .7; margin: 0; }
.sto-img { max-height: 120px; max-width: 100%; object-fit: contain; display: block; margin: 12px auto; }
.sto-facts { margin-top: 12px; font-size: 13px; width: 100%; }
.sto-facts td { padding: 3px 0; }
.sto-facts td:first-child { opacity: .6; width: 38%; }
.sto-tag { font-family: ui-monospace, Menlo, monospace; font-weight: 700; }
/* The same chevron rail /my uses — one design language for every tracker. */
.sto-steps { display: flex; gap: 4px; margin-top: 18px; }
.sto-step { flex: 1; text-align: center; font-size: 11px; font-weight: 600; padding: 9px 4px 9px 12px;
    background: light-dark(#e9e9ee, #33343a); color: light-dark(#5a5a62, #9a9aa4);
    clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 50%, calc(100% - 12px) 100%, 0 100%, 12px 50%); }
.sto-step:first-child { clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 50%, calc(100% - 12px) 100%, 0 100%); }
.sto-step:last-child { clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%, 12px 50%); }
.sto-step.done { background: #00a65a; color: #fff; }
.sto-step.now { background: light-dark(#f39c12, #d68910); color: #fff; }
.sto-order-head { display: flex; align-items: baseline; gap: 10px; margin: 26px 0 12px; flex-wrap: wrap; }
</style>

<div class="row"><div class="col-md-11 col-lg-10">

@if ($orders->isEmpty())
    <div class="box box-default"><div class="box-body">
        <p class="text-muted">{{ trans('admin/store/general.orders_none') }}</p>
    </div></div>
@endif

@foreach ($orders as $order)
    @php
        $status = $order->displayStatus();
        $assets = $assetsByReference->get($order->reference(), collect());
        $deviceItems = $order->items->filter(fn ($line) => $line->catalogItem?->model_id);
        $labelClass = ['pending' => 'label-warning', 'approved' => 'label-info', 'processing' => 'label-info',
                       'with_vendor' => 'label-info', 'quoted' => 'label-info',
                       'ordered' => 'label-success', 'shipped' => 'label-success', 'arrived' => 'label-success',
                       'declined' => 'label-danger', 'cancelled' => 'label-default'][$status] ?? 'label-default';
        // Where this order sits on the five steps a requester thinks in.
        $stepIndex = ['pending' => 0, 'approved' => 1, 'processing' => 1,
                      'with_vendor' => 2, 'quoted' => 2, 'ordered' => 2,
                      'shipped' => 3, 'arrived' => 4][$status] ?? null;
        $steps = ['requested', 'approved', 'ordered', 'shipped', 'arrived'];
    @endphp

    <div class="sto-order-head" @if($loop->first) style="margin-top:4px;" @endif>
        <h4 style="margin:0;">{{ $order->reference() }}</h4>
        <span class="text-muted">{{ $order->created_at->format('M j, Y') }}</span>
        <span class="label {{ $labelClass }}">{{ trans('admin/store/general.order_status_'.$status) }}</span>
        @if ($order->isShared())
            <span class="label label-default">{{ trans('admin/store/general.usage_shared_chip') }}@if ($order->usage_note) · {{ $order->usage_note }}@endif</span>
        @endif
        @if ($order->status === 'pending')
            <form method="POST" action="{{ route('store.orders.cancel', $order->id) }}" style="display:inline; margin-left:auto;">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-xs btn-default">{{ trans('admin/store/general.cancel_order') }}</button>
            </form>
        @endif
    </div>

    @if ($deviceItems->isNotEmpty() && ! in_array($status, ['declined', 'cancelled'], true))
        <div class="sto-split">
            {{-- Left: the machine going out, or the plain statement that
                 there is none. Only on the newest order — history rows
                 below do not re-litigate the handover — and never on a
                 shared cart, which replaces nobody's machine. --}}
            @if ($loop->first && ! $order->isShared())
                <div class="sto-pane sto-pane-muted">
                    <div class="sto-kicker">{{ trans('admin/store/general.status_current_machine') }}</div>
                    @if ($outgoing)
                        <p class="sto-name">{{ $outgoing->name ?: ($outgoing->model->name ?? '') }}</p>
                        <p class="sto-sub">{{ $outgoing->model->name ?? '' }}</p>
                        @if ($outgoing->getImageUrl())
                            <img src="{{ $outgoing->getImageUrl() }}" alt="" class="sto-img">
                        @endif
                        <table class="sto-facts">
                            <tr><td>{{ trans('admin/store/general.status_new_tag') }}</td>
                                <td class="sto-tag">{{ $outgoing->asset_tag }}</td></tr>
                            @if ($outgoing->serial)
                                <tr><td>{{ trans('admin/store/general.status_serial') }}</td>
                                    <td class="sto-tag">{{ $outgoing->serial }}</td></tr>
                            @endif
                        </table>
                        <p class="sto-sub" style="margin-top:12px;">{{ trans('admin/store/general.status_current_le') }}</p>
                        <p class="sto-sub" style="margin-top:6px;">
                            <strong>{{ $buyout
                                ? trans('admin/store/general.status_current_buyout')
                                : trans('admin/store/general.status_current_returning') }}</strong>
                        </p>
                    @else
                        <p class="sto-name">{{ trans('admin/store/general.status_current_none') }}</p>
                        <p class="sto-sub">{{ trans('admin/store/general.status_current_none_sub') }}</p>
                    @endif
                </div>
            @endif

            {{-- Right: the incoming machine, its tag, and where it is. --}}
            <div class="sto-pane">
                <div class="sto-kicker">{{ trans('admin/store/general.status_new_machine') }}</div>
                @foreach ($deviceItems as $line)
                    <p class="sto-name" @if(!$loop->first) style="margin-top:14px;" @endif>{{ $line->catalogItem->family ?: $line->description }}</p>
                    <p class="sto-sub">{{ $line->description }}@if ($line->quantity > 1) &nbsp;×{{ $line->quantity }}@endif</p>
                    @if ($loop->first && $line->catalogItem?->storeImageUrl())
                        <img src="{{ $line->catalogItem->storeImageUrl() }}" alt="" class="sto-img">
                    @endif
                @endforeach

                @if ($assets->isNotEmpty())
                    <table class="sto-facts">
                        @foreach ($assets as $asset)
                            <tr><td>{{ trans('admin/store/general.status_new_tag') }}</td>
                                <td class="sto-tag">{{ $asset->asset_tag }}</td></tr>
                            @if ($asset->serial)
                                <tr><td>{{ trans('admin/store/general.status_serial') }}</td>
                                    <td class="sto-tag">{{ $asset->serial }}</td></tr>
                            @endif
                        @endforeach
                    </table>
                    @unless ($assets->contains(fn ($asset) => filled($asset->serial)))
                        <p class="sto-sub" style="margin-top:6px;">{{ trans('admin/store/general.status_new_tag_sub') }}</p>
                    @endunless
                @endif

                @if ($order->tracking_number)
                    <p class="sto-sub" style="margin-top:10px;">
                        {{ trans('admin/store/general.order_tracking') }}
                        <span class="sto-tag">{{ $order->tracking_number }}</span>
                    </p>
                @endif

                @if ($stepIndex !== null)
                    <div class="sto-steps">
                        @foreach ($steps as $i => $step)
                            <div class="sto-step {{ $i < $stepIndex ? 'done' : ($i === $stepIndex ? 'now' : '') }}">
                                {{ trans('admin/store/general.status_step_'.$step) }}
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Accessory lines ride below the panes rather than inside them. --}}
        @php($extras = $order->items->reject(fn ($line) => $line->catalogItem?->model_id))
        @if ($extras->isNotEmpty())
            <p class="text-muted" style="margin:10px 2px 0; font-size:13px;">
                + {{ $extras->map(fn ($line) => $line->description.($line->quantity > 1 ? ' ×'.$line->quantity : ''))->implode(', ') }}
            </p>
        @endif
    @else
        {{-- Accessory-only, declined or cancelled orders: a plain compact list. --}}
        <div class="box box-default"><div class="box-body">
            @foreach ($order->items as $line)
                <div style="font-size:13px;">{{ $line->description }}@if ($line->quantity > 1) ×{{ $line->quantity }}@endif</div>
            @endforeach
            @if ($order->decision_notes && $order->status === 'declined')
                <p class="text-muted" style="margin:8px 0 0;"><em>{{ $order->decision_notes }}</em></p>
            @endif
        </div></div>
    @endif
@endforeach

{{ $orders->links() }}

</div></div>
@stop
