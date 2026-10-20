@extends('layouts/default')

@section('title')
    {{ trans('admin/orders/general.orders') }}
    @parent
@stop

{{-- The orders board: everything in flight, each in exactly one stage, from
     the request waiting on a decision to the hardware waiting to be handed
     to someone. The procurement pipeline's look and lightbox, but every
     column is something somebody acts on, so each card's lightbox carries
     the real forms for its stage. Those forms post to the same routes the
     approvals queue and the order page use, with return_to=orders so the
     answer lands back here.

     Needs $board (App\Services\OrdersBoard::build()), $etaCandidates,
     $fundingAccounts, $leaseSchedules, $canApprove and $canUpdate. --}}

@section('content')

@include('partials.pipeline-board.styles')
@include('procurement._queue-styles')

<style>
    .ord-board { --pp-cols: 6; }
    .ord-board.ord-board--no-done { --pp-cols: 5; }
    .ord-board .pp-col-foot { font-size: 11.5px; padding: 2px 2px 10px; }
    .ord-board .pp-col-foot a { color: var(--pp-c); font-weight: 600; }
    #ppModal .ord-modal-actions { margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--pp-line); }
    #ppModal .ord-modal-actions .btn + .btn, #ppModal .ord-modal-actions form + .btn { margin-left: 6px; }
    #ppModal .ord-help { font-size: 11.5px; color: var(--pp-ink2); margin: 6px 0 0; }
</style>

@php
    $fmt = fn ($v) => '$'.\App\Helpers\Helper::formatCurrencyOutput($v);
    $t = fn ($key, $repl = []) => trans('admin/orders/general.'.$key, $repl);
    $storeSum = fn ($orders) => $orders->sum(fn ($o) => \App\Services\OrdersBoard::storeTotal($o));
    $orderSum = fn ($orders) => $orders->sum(fn ($o) => \App\Services\OrdersBoard::orderTotal($o));
    $linesIn = fn ($orders) => [
        'received' => $orders->sum(fn ($o) => $o->items->filter->isReceived()->count()),
        'total' => $orders->sum(fn ($o) => $o->items->count()),
    ];

    $vendorCount = $board['unsent']->count() + $board['withVendor']->count() + $board['sentRequisitions']->count();
    $arrivingLines = $linesIn($board['arriving']);
    $etaAsked = $board['ordered']->merge($board['arriving'])->filter(fn ($o) => $o->eta_requested_at)->count();

    // Stage => [token color, count, headline, notes]. The colors reuse the
    // pipeline's validated six-slot palette in order.
    $stages = [
        'approvals' => [
            'color' => 'var(--pp-budgeting)',
            'count' => $board['approvals']->count(),
            'big' => $fmt($storeSum($board['approvals'])),
            'notes' => [$t('board_note_orders', ['count' => $board['approvals']->count()])],
        ],
        'vendor' => [
            'color' => 'var(--pp-ordering)',
            'count' => $vendorCount,
            'big' => $fmt($storeSum($board['unsent']) + $storeSum($board['withVendor'])
                + $board['sentRequisitions']->sum(fn ($r) => $r->vendorTotal())),
            'notes' => array_values(array_filter([
                $t('board_note_orders', ['count' => $board['withVendor']->count()]),
                $board['unsent']->isNotEmpty() ? $t('board_note_unsent', ['count' => $board['unsent']->count()]) : null,
                $board['sentRequisitions']->isNotEmpty() ? $t('board_note_requisitions', ['count' => $board['sentRequisitions']->count()]) : null,
            ])),
        ],
        'ordered' => [
            'color' => 'var(--pp-processing)',
            'count' => $board['ordered']->count(),
            'big' => $fmt($orderSum($board['ordered'])),
            'notes' => array_values(array_filter([
                $t('board_note_orders', ['count' => $board['ordered']->count()]),
                $etaAsked ? $t('board_note_eta_asked', ['count' => $etaAsked]) : null,
            ])),
        ],
        'arriving' => [
            'color' => 'var(--pp-deploying)',
            'count' => $board['arriving']->count(),
            'big' => $fmt($orderSum($board['arriving'])),
            'notes' => [$t('board_note_lines_in', $arrivingLines)],
        ],
        'allocate' => [
            'color' => 'var(--pp-reconciling)',
            'count' => $board['allocateCount'],
            'big' => (string) $board['allocateCount'],
            'notes' => [$t('board_note_devices', ['count' => $board['allocateCount']])],
        ],
        'done' => [
            'color' => 'var(--pp-completed)',
            'count' => $board['doneCount'],
            'big' => (string) $board['doneCount'],
            'notes' => $board['doneCount'] > $board['done']->count()
                ? [$t('board_note_recent', ['count' => $board['done']->count()])]
                : [],
        ],
    ];

    if (! $showDone) {
        unset($stages['done']);
    }
@endphp

<div class="row">
    <div class="col-md-12">
        <div class="box box-default proc-pipe ord-board {{ $showDone ? '' : 'ord-board--no-done' }}">
            <div class="box-header with-border" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <input type="search" id="pp-board-filter" class="form-control input-sm" style="width: 220px;"
                       placeholder="{{ $t('board_filter') }}" aria-label="{{ $t('board_filter') }}">
                <div class="btn-group btn-group-sm" role="group">
                    <a href="{{ route('orders.index') }}" class="btn btn-primary" aria-current="page">{{ $t('board_view_board') }}</a>
                    <a href="{{ route('orders.index', ['view' => 'list']) }}" class="btn btn-default">{{ $t('board_view_list') }}</a>
                </div>
                <a href="#" id="pp-filter-clear-top" class="btn btn-default btn-sm">{{ $t('board_filter_clear') }}</a>
                <a href="{{ route('orders.index', $showDone ? [] : ['done' => 1]) }}" class="btn btn-default btn-sm" aria-pressed="{{ $showDone ? 'true' : 'false' }}">
                    {{ $showDone ? $t('board_hide_done') : $t('board_show_done', ['count' => $board['doneCount']]) }}
                </a>
                <span style="margin-left:auto;"></span>
                @if ($canUpdate)
                    @include('orders._eta-request-bulk')
                @endif
                @can('create', \App\Models\Order::class)
                    <a href="{{ route('orders.create') }}" class="btn btn-sm btn-primary">{{ trans('general.create') }}</a>
                @endcan
            </div>
            <div class="box-body">
                <div class="pp-board-scroll">
                    <div class="pp-rail" id="pp-rail">
                        @foreach ($stages as $key => $stage)
                            <div class="pp-chev" style="--pp-c: {{ $stage['color'] }}"
                                 data-pp-stage="{{ $key }}" tabindex="0" role="button" aria-pressed="false">
                                <div class="pp-stage">{{ $t('board_stage_'.$key) }}
                                    <span class="pp-count">{{ $stage['count'] }}</span>
                                </div>
                                <div class="pp-big">{{ $stage['big'] }}</div>
                                @foreach ($stage['notes'] as $note)
                                    <div class="pp-note">{{ $note }}</div>
                                @endforeach
                                <div class="pp-note pp-def">{{ $t('board_def_'.$key) }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="pp-board">

                        {{-- Approvals --}}
                        <div class="pp-col" data-pp-stage="approvals" style="--pp-c: {{ $stages['approvals']['color'] }}">
                            <div class="pp-col-cards">
                                @foreach ($board['approvals'] as $order)
                                    <div class="pp-card" data-pp-modal="approve-{{ $order->id }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $order->reference() }}</div>
                                        <div class="pp-d">{{ $order->user?->present()->fullName }} · <span class="pp-money">{{ $fmt($order->total()) }}</span></div>
                                        <div class="pp-chips">
                                            <span class="pp-chip pp-chip-wait">{{ trans('admin/store/general.order_status_pending') }}</span>
                                            @if ($order->gl_code)<span class="pp-chip">{{ $t('board_gl') }} {{ $order->gl_code }}</span>@endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- With vendor --}}
                        <div class="pp-col" data-pp-stage="vendor" style="--pp-c: {{ $stages['vendor']['color'] }}">
                            <div class="pp-col-cards">
                                @foreach ($board['unsent'] as $order)
                                    <div class="pp-card" data-pp-modal="store-{{ $order->id }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $order->reference() }}</div>
                                        <div class="pp-d">{{ $order->user?->present()->fullName }} · <span class="pp-money">{{ $fmt($order->total()) }}</span></div>
                                        <div class="pp-chips">
                                            @if ($order->readyForVendor())
                                                <span class="pp-chip pp-chip-need">{{ $t('board_chip_not_sent') }}</span>
                                            @else
                                                <span class="pp-chip pp-chip-need">{{ $t('board_chip_needs_account') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                                @foreach ($board['withVendor'] as $order)
                                    <div class="pp-card" data-pp-modal="store-{{ $order->id }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $order->reference() }}</div>
                                        <div class="pp-d">{{ $order->user?->present()->fullName }} · <span class="pp-money">{{ $fmt($order->total()) }}</span></div>
                                        <div class="pp-chips">
                                            <span class="pp-chip pp-chip-wait">{{ trans('admin/store/general.order_status_'.$order->displayStatus()) }}</span>
                                            @if ($order->quote_number)<span class="pp-chip">{{ $order->quote_number }}</span>@endif
                                        </div>
                                    </div>
                                @endforeach
                                @foreach ($board['sentRequisitions'] as $requisition)
                                    <div class="pp-card" data-pp-modal="req-{{ $requisition->id }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $requisition->display_name }}@if ($requisition->supplier) · {{ $requisition->supplier->name }}@endif</div>
                                        <div class="pp-d">{{ $requisition->title ?: '—' }} · <span class="pp-money">{{ $fmt($requisition->vendorTotal()) }}</span></div>
                                        <div class="pp-chips">
                                            <span class="pp-chip pp-chip-po">{{ $t('board_chip_requisition') }}</span>
                                            <span class="pp-chip pp-chip-wait">{{ trans('admin/store/general.order_status_with_vendor') }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Ordered and Arriving share a card: an Order --}}
                        @foreach (['ordered', 'arriving'] as $column)
                            <div class="pp-col" data-pp-stage="{{ $column }}" style="--pp-c: {{ $stages[$column]['color'] }}">
                                <div class="pp-col-cards">
                                    @foreach ($board[$column] as $order)
                                        <div class="pp-card" data-pp-modal="order-{{ $order->id }}" tabindex="0" role="button">
                                            <div class="pp-t">{{ $order->order_number }}@if ($order->supplier) · {{ $order->supplier->name }}@endif</div>
                                            <div class="pp-d">
                                                @if ($column === 'arriving')
                                                    {{ $t('board_received', ['received' => $order->items->filter->isReceived()->count(), 'total' => $order->items->count()]) }}
                                                @else
                                                    {{ trans('admin/purchase-orders/general.pipeline_items', ['count' => $order->items->count()]) }}
                                                @endif
                                                · <span class="pp-money">{{ $fmt(\App\Services\OrdersBoard::orderTotal($order)) }}</span>
                                            </div>
                                            <div class="pp-chips">
                                                @if ($order->purchaseOrder)<span class="pp-chip pp-chip-po">{{ $order->purchaseOrder->po_number }}</span>@endif
                                                @if ($column === 'arriving')
                                                    <span class="pp-chip pp-chip-wait">{{ trans('admin/orders/general.status_'.$order->status) }}</span>
                                                @endif
                                                @if ($order->eta_requested_at)
                                                    <span class="pp-chip">{{ $t('board_chip_eta_asked', ['date' => $order->eta_requested_at->format('M j')]) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        {{-- Allocate --}}
                        <div class="pp-col" data-pp-stage="allocate" style="--pp-c: {{ $stages['allocate']['color'] }}">
                            <div class="pp-col-cards">
                                @foreach ($board['allocate'] as $group)
                                    <div class="pp-card" data-pp-modal="alloc-{{ $group['order']?->id ?? 0 }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $group['order']?->order_number ?? $t('board_arrivals_no_order') }}@if ($group['order']?->supplier) · {{ $group['order']->supplier->name }}@endif</div>
                                        <div class="pp-d">{{ $t('board_note_devices', ['count' => $group['assets']->count()]) }}</div>
                                        <div class="pp-chips">
                                            @foreach ($group['assets']->pluck('model.name')->filter()->unique()->take(2) as $model)
                                                <span class="pp-chip">{{ $model }}</span>
                                            @endforeach
                                            @unless ($group['order'])<span class="pp-chip pp-chip-need">{{ $t('board_chip_no_order') }}</span>@endunless
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            @if ($board['allocateCount'] > 0)
                                <div class="pp-col-foot"><a href="{{ route('orders.unmatched') }}">{{ $t('board_open_workbench') }} →</a></div>
                            @endif
                        </div>

                        {{-- Done, only when asked for --}}
                        @if ($showDone)
                        <div class="pp-col" data-pp-stage="done" style="--pp-c: {{ $stages['done']['color'] }}">
                            <div class="pp-col-cards">
                                @foreach ($board['done'] as $order)
                                    <div class="pp-card" data-pp-modal="done-{{ $order->id }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $order->order_number }}@if ($order->supplier) · {{ $order->supplier->name }}@endif</div>
                                        <div class="pp-d">{{ ($order->order_date ?? $order->created_at)?->format('Y-m-d') }} · <span class="pp-money">{{ $fmt($order->order_cost ?? 0) }}</span></div>
                                        <div class="pp-chips"><span class="pp-chip pp-chip-done">{{ trans('admin/orders/general.status_received') }}</span></div>
                                    </div>
                                @endforeach
                            </div>
                            @if ($board['doneCount'] > 0)
                                <div class="pp-col-foot"><a href="{{ route('orders.index', ['view' => 'list', 'status' => 'received']) }}">{{ $t('board_open_history') }} →</a></div>
                            @endif
                        </div>
                        @endif

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Lightbox content, one block per card. Moved into the modal when its
     card opens, so the forms inside are the only copies on the page. --}}
<div class="hidden" id="pp-modal-store">

    @foreach ($board['approvals'] as $order)
        <div data-pp-content="approve-{{ $order->id }}" data-pp-color="{{ $stages['approvals']['color'] }}" data-pp-title="{{ $order->reference() }}">
            @include('orders._board-store-facts', ['order' => $order])
            @if ($canApprove)
                <div class="ord-modal-actions">
                    @include('procurement._queue-funding', ['order' => $order, 'formId' => 'pq-decide-'.$order->id])
                    <textarea class="form-control input-sm pq-note-input" rows="2" form="pq-decide-{{ $order->id }}" name="decision_notes"
                              placeholder="{{ trans('admin/store/general.queue_decision_note') }}"></textarea>
                    <div class="pq-actions">
                        <button type="submit" form="pq-decide-{{ $order->id }}" name="decision" value="approved" class="pq-btn pq-btn--approve">
                            {{ trans('admin/store/general.queue_approve') }}
                        </button>
                        <button type="submit" form="pq-decide-{{ $order->id }}" name="decision" value="declined" class="pq-btn pq-btn--quiet">
                            {{ trans('admin/store/general.queue_decline') }}
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endforeach

    @foreach ($board['unsent']->merge($board['withVendor']) as $order)
        <div data-pp-content="store-{{ $order->id }}" data-pp-color="{{ $stages['vendor']['color'] }}" data-pp-title="{{ $order->reference() }}">
            @include('orders._board-store-facts', ['order' => $order])
            @if ($canUpdate)
                <div class="ord-modal-actions">
                    @include('procurement._queue-approved-actions', ['order' => $order])
                </div>
            @endif
        </div>
    @endforeach

    @foreach ($board['sentRequisitions'] as $requisition)
        <div data-pp-content="req-{{ $requisition->id }}" data-pp-color="{{ $stages['vendor']['color'] }}" data-pp-title="{{ $requisition->display_name }}">
            <div class="pp-facts">
                <span>{{ trans('general.total_cost') }}<b class="pp-money">{{ $fmt($requisition->vendorTotal()) }}</b></span>
                <span>{{ $t('board_lines') }}<b>{{ $requisition->items->count() }}</b></span>
                @if ($requisition->purchaseOrder)<span>{{ $t('board_purchase_order') }}<b>{{ $requisition->purchaseOrder->po_number }}</b></span>@endif
                @if ($requisition->vendor_sent_at)<span>{{ $t('board_sent') }}<b>{{ $requisition->vendor_sent_at->format('Y-m-d') }}</b></span>@endif
            </div>
            @include('orders._board-lines', ['lines' => $requisition->items])
            <div class="ord-modal-actions">
                <a class="btn btn-primary btn-sm" href="{{ route('purchase-orders.builder', ['requisition' => $requisition->id]) }}">{{ $t('board_open_requisition') }}</a>
            </div>
        </div>
    @endforeach

    @foreach ($board['ordered']->merge($board['arriving']) as $order)
        @php($column = $board['ordered']->contains($order) ? 'ordered' : 'arriving')
        <div data-pp-content="order-{{ $order->id }}" data-pp-color="{{ $stages[$column]['color'] }}" data-pp-title="{{ $order->order_number }}">
            <div class="pp-facts">
                <span>{{ trans('general.total_cost') }}<b class="pp-money">{{ $fmt(\App\Services\OrdersBoard::orderTotal($order)) }}</b></span>
                <span>{{ $t('board_lines') }}<b>{{ $t('board_received', ['received' => $order->items->filter->isReceived()->count(), 'total' => $order->items->count()]) }}</b></span>
                @if ($order->purchaseOrder)<span>{{ $t('board_purchase_order') }}<b>{{ $order->purchaseOrder->po_number }}</b></span>@endif
                @if ($order->vendor_sent_at ?? $order->order_date)<span>{{ $t('board_sent') }}<b>{{ ($order->vendor_sent_at ?? $order->order_date)->format('Y-m-d') }}</b></span>@endif
            </div>
            @include('orders._board-lines', ['lines' => $order->items, 'withReceived' => true])
            <div class="ord-modal-actions">
                <a class="btn btn-primary btn-sm js-lightbox" href="{{ route('orders.show', $order) }}">{{ trans('admin/orders/general.open_order') }}</a>
                @if ($canUpdate && $order->items->contains(fn ($line) => ! $line->isReceived()))
                    <form method="POST" action="{{ route('orders.eta-request', $order) }}" style="display:inline;">
                        {{ csrf_field() }}
                        <input type="hidden" name="return_to" value="orders">
                        <button type="submit" class="btn btn-default btn-sm">
                            <i class="fas fa-truck-fast" aria-hidden="true"></i> {{ $t('board_ask_eta') }}
                        </button>
                    </form>
                    <p class="ord-help">
                        @if ($order->eta_requested_at){{ $t('board_chip_eta_asked', ['date' => $order->eta_requested_at->format('M j')]) }}. @endif
                        {{ $t('board_ask_eta_help') }}
                    </p>
                @endif
            </div>
        </div>
    @endforeach

    @foreach ($board['allocate'] as $group)
        <div data-pp-content="alloc-{{ $group['order']?->id ?? 0 }}" data-pp-color="{{ $stages['allocate']['color'] }}" data-pp-title="{{ $group['order']?->order_number ?? $t('board_arrivals_no_order') }}">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead><tr>
                        <th>{{ trans('general.asset_tag') }}</th>
                        <th>{{ $t('board_model') }}</th>
                        <th>{{ $t('board_serial') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($group['assets'] as $asset)
                            <tr>
                                <td><a class="js-lightbox" href="{{ route('hardware.show', $asset) }}">{{ $asset->asset_tag }}</a></td>
                                <td>{{ $asset->model?->name ?? '—' }}</td>
                                <td>{{ $asset->serial }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ord-modal-actions">
                <a class="btn btn-primary btn-sm" href="{{ route('orders.unmatched') }}">{{ $t('board_open_workbench') }}</a>
                @if ($group['order'])
                    <a class="btn btn-default btn-sm js-lightbox" href="{{ route('orders.show', $group['order']) }}">{{ trans('admin/orders/general.open_order') }}</a>
                @endif
            </div>
        </div>
    @endforeach

    @foreach ($showDone ? $board['done'] : [] as $order)
        <div data-pp-content="done-{{ $order->id }}" data-pp-color="{{ $stages['done']['color'] }}" data-pp-title="{{ $order->order_number }}" data-pp-url="{{ route('orders.show', $order) }}"></div>
    @endforeach

    {{-- The forms the lightbox buttons submit through form="…". --}}
    @foreach ($board['approvals'] as $order)
        <form method="POST" action="{{ route('procurement.queue.decide', $order->id) }}" id="pq-decide-{{ $order->id }}">
            {{ csrf_field() }}
            <input type="hidden" name="return_to" value="orders">
        </form>
    @endforeach
    @foreach ($board['unsent'] as $order)
        <form method="POST" action="{{ route('procurement.queue.send-vendor') }}" id="pq-vendor-{{ $order->id }}">
            {{ csrf_field() }}
            <input type="hidden" name="orders[]" value="{{ $order->id }}">
            <input type="hidden" name="return_to" value="orders">
        </form>
        <form method="POST" action="{{ route('procurement.queue.send-vendor') }}" id="pq-vendor-test-{{ $order->id }}">
            {{ csrf_field() }}
            <input type="hidden" name="orders[]" value="{{ $order->id }}">
            <input type="hidden" name="test" value="1">
            <input type="hidden" name="return_to" value="orders">
        </form>
        <form method="POST" action="{{ route('procurement.queue.funding', $order->id) }}" id="pq-funding-{{ $order->id }}">
            {{ csrf_field() }}
            <input type="hidden" name="return_to" value="orders">
        </form>
    @endforeach
    @foreach ($board['withVendor'] as $order)
        @if ($order->vendor_sent_at && ! $order->confirmed_at)
            <form method="POST" action="{{ route('procurement.queue.quote', $order->id) }}" id="pq-quote-{{ $order->id }}">
                {{ csrf_field() }}
                <input type="hidden" name="return_to" value="orders">
            </form>
        @endif
    @endforeach
</div>

@include('partials.pipeline-board.modal', ['stageNames' => collect(array_keys($stages))->mapWithKeys(fn ($k) => [$k => $t('board_stage_'.$k)])->all()])

@if ($canUpdate)
    @include('orders._eta-sheet')
@endif

<script nonce="{{ csrf_token() }}">
    // The CSI schedule field only means anything on a lease, so it follows
    // the account selection. Same rule as the approvals queue.
    (function () {
        var LEASE_ACCOUNTS = @json(collect(array_keys(\App\Services\SupplierAccounts::accounts()))->filter(fn ($key) => \App\Services\SupplierAccounts::needsSchedule($key))->values());
        document.querySelectorAll('select[data-lease-toggle]').forEach(function (select) {
            var group = document.getElementById(select.dataset.leaseToggle);
            if (! group) { return; }
            select.addEventListener('change', function () {
                group.hidden = ! LEASE_ACCOUNTS.includes(select.value);
            });
        });
    })();
</script>

@stop
