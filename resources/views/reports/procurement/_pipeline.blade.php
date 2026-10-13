{{-- Procurement pipeline: chevron rail (FY money, stage by stage) and the
     device board (each order/device in exactly one stage). Included from the
     dashboard, so the parent view's variables are in scope: $selectedFy,
     $totalBudget, $plannedTotal, $totalRemaining, $totalCommitted,
     $totalInvoiced, $eolCount, $eolEstimate, $leaseExpiryTotal,
     $leaseExpiryApplied, $capitalOrdered,
     $leaseExpiryCount, $poCount, $pendingApprovalCount,
     $scheduleSigningQueueCount,
     $liveCarry and $pipeline (from App\Services\ProcurementPipeline).

     Stage colors are a fixed six-slot categorical palette, validated for
     adjacent-pair CVD separation and 3:1 surface contrast in BOTH themes
     (each light-dark() pair was checked against its surface) — don't
     reorder or substitute hues casually. --}}

@php
    // One row per chevron. Notes are one stat per line — the rail is read
    // top-to-bottom inside a stage, left-to-right across the year.
    $fmt = fn ($v) => '$'.\App\Helpers\Helper::formatCurrencyOutput($v);
    $t = fn ($key, $repl = []) => trans('admin/purchase-orders/general.'.$key, $repl);
    $cardCounts = [
        'budgeting' => count($pipeline['planned']) + $pipeline['plannedMore'] + count($pipeline['requisitionCards'] ?? []),
        'ordering' => count($pipeline['open']) + $pipeline['openMore'] + count($pipeline['storeQueue'] ?? [])
            + count($pipeline['sentRequisitionCards'] ?? []),
        'deploying' => count($pipeline['processing']) + $pipeline['processingMore'],
        'reconciling' => count($pipeline['pendingInvoices']) + $pipeline['pendingInvoicesMore'],
        'completed' => $pipeline['completedCount'],
    ];
    $stages = [
        'budgeting' => [
            'big' => $fmt($totalBudget),
            'notes' => array_values(array_filter([
                $t('pipeline_note_approved'),
                $plannedTotal > 0 ? $t('pipeline_note_planned', ['amount' => $fmt($plannedTotal)]) : null,
                $t('pipeline_note_remaining', ['amount' => $fmt($totalRemaining)]),
                $t('pipeline_note_eol', ['count' => $eolCount, 'cost' => $fmt($eolEstimate)]),
                // Once part of the envelope has become a purchase order, the
                // note says how much of it still stands — the rest is in the
                // pot already, as that PO's own budget.
                ($capitalOrdered ?? 0) > 0
                    ? $t('pipeline_note_lease_preapproval_partial', [
                        'applied' => $fmt($leaseExpiryApplied),
                        'cost' => $fmt($leaseExpiryTotal),
                        'count' => $leaseExpiryCount,
                    ])
                    : $t('pipeline_note_lease_preapproval', ['cost' => $fmt($leaseExpiryTotal), 'count' => $leaseExpiryCount]),
                ($pipeline['openRequisitions'] ?? 0) > 0
                    ? $t('pipeline_note_requisitions', ['count' => $pipeline['openRequisitions']])
                    : null,
                ($liveCarry ?? null)
                    ? $t('card_budget_incl_carry', ['amount' => $fmt($liveCarry['unused']), 'source' => $liveCarry['source_fy']])
                    : null,
            ])),
        ],
        'ordering' => [
            'big' => $fmt($totalCommitted),
            'notes' => array_values(array_filter([
                $t('pipeline_note_committed'),
                $t('pipeline_note_pos', ['count' => $poCount]),
                $t('pipeline_note_open_orders', ['count' => count($pipeline['open']) + $pipeline['openMore']]),
                // Counted apart: "awaiting review" and "approved, waiting
                // for a PO" are different things to act on, and lumping
                // them was how an approved order looked like it had gone.
                ($awaitingReview = collect($pipeline['storeQueue'] ?? [])->where('status', 'pending')->count())
                    ? $t('pipeline_note_awaiting_review', ['count' => $awaitingReview])
                    : null,
                ($approvedWaiting = collect($pipeline['storeQueue'] ?? [])->where('status', 'approved')->count())
                    ? $t('pipeline_note_approved_waiting', ['count' => $approvedWaiting])
                    : null,
            ])),
        ],
        // Processing and Deploying are one stage: the physical span from
        // "boxes received" to "device in place / in hand". The detail —
        // waves, provisioning, rooms, scheduling — lives on the Deployments
        // board, which this chevron links to. Orders sit here until the
        // deployment side confirms the hand-off.
        //
        // Devices only. Agreements used to make up the bulk of both the
        // count and the notes here — 74 of an "83" that read as things
        // arriving — but an agreement is paperwork attached to a device,
        // not a unit moving down an order pipeline, and it was double
        // counting the same laptops the staging figure already held. The
        // agreement lifecycle has its own report.
        'deploying' => [
            'big' => (string) $pipeline['stagedItemCount'],
            'notes' => array_values(array_filter([
                $pipeline['stagedItemCount'] ? $t('pipeline_note_staged_count', ['count' => $pipeline['stagedItemCount']]) : null,
                $t('pipeline_note_returns_prep', ['count' => count($pipeline['returns']['prep']['cards']) + $pipeline['returns']['prep']['more']]),
            ])),
            'link' => route('reports.deployments'),
        ],
        'reconciling' => [
            'big' => $fmt($totalInvoiced),
            'notes' => [
                $t('pipeline_note_invoiced'),
                $t('pipeline_note_invoices_pending', ['count' => $pendingApprovalCount]),
                $t('pipeline_note_schedules_unsigned', ['count' => $scheduleSigningQueueCount]),
            ],
        ],
        'completed' => [
            'big' => (string) $pipeline['completedCount'],
            'notes' => [$t('pipeline_completed_note')],
        ],
    ];

    foreach ($stages as $stageKey => $stageRow) {
        // Budgeting has no definition line: it read "Planned order lines.
        // Exit gate: PO # attached", which restated the lock badge that used
        // to sit under it and the "Needs PO" chip on every card in the
        // column. The other four stages describe something the board itself
        // does not say.
        if ($stageKey !== 'budgeting') {
            $stages[$stageKey]['def'] = $t('pipeline_col_'.$stageKey.'_def');
        }
        $stages[$stageKey]['cardCount'] = $cardCounts[$stageKey] ?? 0;
    }

@endphp

@include('partials.pipeline-board.styles')

{{-- ═══ Chevron rail ═══ --}}
<div class="row">
    <div class="col-md-12">
        <div class="box box-default proc-pipe">
            <div class="box-header with-border" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <h3 class="box-title" style="margin:0;">{{ trans('admin/purchase-orders/general.pipeline_title_plain') }}</h3>
                <input type="search" id="pp-board-filter" class="form-control input-sm"
                       placeholder="{{ trans('admin/purchase-orders/general.pipeline_board_filter') }}"
                       style="width: 200px;"
                       aria-label="{{ trans('admin/purchase-orders/general.pipeline_board_filter') }}">
                @can('budget_allocations.manage')
                    <a href="#" data-toggle="modal" data-target="#budgetAllocationsModal" class="btn btn-default btn-sm">
                        {{ trans('admin/budget-allocations/general.allocations') }}
                    </a>
                @endcan
                <div style="margin-left:auto; display:flex; align-items:center; gap:6px;">
                    <a href="#" id="pp-filter-clear-top" class="btn btn-default btn-sm">
                        {{ trans('admin/purchase-orders/general.pipeline_filter_clear_top') }}
                    </a>
                </div>
            </div>
            <div class="box-body">
                <div class="pp-board-scroll">
                    <div class="pp-rail" id="pp-rail">
                        @foreach ($stages as $key => $stage)
                            <div class="pp-chev"
                                 style="--pp-c: var(--pp-{{ $key }})"
                                 data-pp-stage="{{ $key }}" tabindex="0" role="button" aria-pressed="false">
                                <div class="pp-stage">{{ trans('admin/purchase-orders/general.stage_'.$key) }}
                                    <span class="pp-count">{{ $stage['cardCount'] }}</span>
                                </div>
                                <div class="pp-big">{{ $stage['big'] }}</div>
                                @foreach ($stage['notes'] as $note)
                                    <div class="pp-note">{{ $note }}</div>
                                @endforeach
                                @if ($stage['link'] ?? false)
                                    <a class="pp-note pp-golink" href="{{ $stage['link'] }}">
                                        {{ trans('admin/purchase-orders/general.pipeline_open_deployments') }}
                                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                    </a>
                                @endif
                                @if ($stage['def'] ?? false)
                                    <div class="pp-note pp-def">{{ $stage['def'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- The chevrons sit directly on top of their stage
                         columns — same tracks, same scroller, no seam. --}}
                    <div class="pp-board">

                        {{-- Budgeting --}}
                        <div class="pp-col" data-pp-stage="budgeting" style="--pp-c: var(--pp-budgeting)">
                            <div class="pp-col-cards">
                                @foreach ($pipeline['planned'] as $card)
                                    <div class="pp-card" data-pp-modal="planned-{{ $card['id'] }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $card['order_number'] }}</div>
                                        <div class="pp-d">
                                            {{ trans('admin/purchase-orders/general.pipeline_items', ['count' => $card['items_count']]) }}
                                            · <span class="pp-money">{{ $fmt($card['total']) }}</span>
                                        </div>
                                        <div class="pp-chips">
                                            @if ($card['po_number'])
                                                <span class="pp-chip pp-chip-po">{{ $card['po_number'] }}</span>
                                            @else
                                                <span class="pp-chip pp-chip-need">{{ trans('admin/purchase-orders/general.pipeline_needs_po') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                                @foreach ($pipeline['requisitionCards'] ?? [] as $reqCard)
                                    <div class="pp-card" data-pp-modal="reqm-{{ $reqCard['id'] }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $reqCard['number'] }}</div>
                                        <div class="pp-d">{{ $reqCard['title'] ?: '—' }} · <span class="pp-money">{{ $fmt($reqCard['total']) }}</span></div>
                                        <div class="pp-chips">
                                            <span class="pp-chip pp-chip-wait">{{ trans('admin/purchase-orders/general.pipeline_chip_reqm') }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Ordering --}}
                        <div class="pp-col" data-pp-stage="ordering" style="--pp-c: var(--pp-ordering)">
                            <div class="pp-col-cards">
                                @foreach ($pipeline['sentRequisitionCards'] ?? [] as $sentCard)
                                    <div class="pp-card" data-pp-modal="sentreq-{{ $sentCard['id'] }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $sentCard['number'] }}@if ($sentCard['supplier']) · {{ $sentCard['supplier'] }}@endif</div>
                                        <div class="pp-d">{{ $sentCard['title'] ?: '—' }} · <span class="pp-money">{{ $fmt($sentCard['total']) }}</span></div>
                                        <div class="pp-chips">
                                            <span class="pp-chip pp-chip-po">{{ $sentCard['number'] }}</span>
                                            <span class="pp-chip pp-chip-wait">{{ trans('admin/purchase-orders/general.pipeline_chip_with_vendor') }}</span>
                                            @if ($sentCard['quote_number'])
                                                <span class="pp-chip">{{ $sentCard['quote_number'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                                @foreach ($pipeline['storeQueue'] ?? [] as $queueCard)
                                    <div class="pp-card" data-pp-modal="storeq-{{ $queueCard['id'] }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $queueCard['number'] }}</div>
                                        <div class="pp-d">{{ $queueCard['requester'] }} · <span class="pp-money">{{ $fmt($queueCard['total']) }}</span></div>
                                        <div class="pp-chips">
                                            {{-- Which of the two it is. An approved order
                                                 sitting here is waiting on a PO, not on a
                                                 decision, and the card should not ask for
                                                 one twice. --}}
                                            @if (($queueCard['status'] ?? 'pending') === 'ordered')
                                                <span class="pp-chip pp-chip-wait">{{ trans('admin/store/general.order_status_'.$queueCard['stage']) }}</span>
                                            @elseif (($queueCard['status'] ?? 'pending') === 'approved')
                                                <span class="pp-chip pp-chip-done">{{ trans('admin/purchase-orders/general.pipeline_chip_approved') }}</span>
                                            @else
                                                <span class="pp-chip pp-chip-wait">{{ trans('admin/purchase-orders/general.pipeline_chip_awaiting') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                                @foreach ($pipeline['open'] as $card)
                                    <div class="pp-card" data-pp-modal="order-{{ $card['id'] }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $card['order_number'] }}@if ($card['supplier']) · {{ $card['supplier'] }}@endif</div>
                                        <div class="pp-d">
                                            {{ trans('admin/purchase-orders/general.pipeline_items', ['count' => $card['items_count']]) }}
                                            · <span class="pp-money">{{ $fmt($card['total']) }}</span>
                                        </div>
                                        <div class="pp-chips">
                                            @if ($card['po_number'])<span class="pp-chip pp-chip-po">{{ $card['po_number'] }}</span>@endif
                                            <span class="pp-chip pp-chip-wait">{{ $card['status'] }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Deploying — the merged physical stage: received
                             orders (device lines in staging) plus checkout
                             in flight. Deep detail lives on the Deployments
                             board. --}}
                        <div class="pp-col" data-pp-stage="deploying" style="--pp-c: var(--pp-deploying)">
                            <div class="pp-col-cards">
                                {{-- Agreements out for signature — restored as an
                                     indicator after leaving the column's cards: it
                                     counts agreements, not devices, so it adds
                                     nothing to the staging figures, and it links to
                                     the ledger where the rows are worked. --}}
                                @if (($pipeline['awaitingSignature'] ?? 0) > 0)
                                    <a href="{{ route('reports.procurement.user-agreement-ledger', ['stage' => 'agreement_sent']) }}" class="pp-card" style="display:block;">
                                        <div class="pp-d">
                                            <i class="fa-solid fa-signature" aria-hidden="true"></i>
                                            {{ trans('admin/purchase-orders/general.pipeline_agreements_sent', ['count' => $pipeline['awaitingSignature']]) }}
                                        </div>
                                    </a>
                                @endif
                                @foreach ($pipeline['processing'] as $card)
                                    <div class="pp-card" data-pp-modal="order-{{ $card['id'] }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $card['order_number'] }}</div>
                                        <div class="pp-d">
                                            {{ trans('admin/purchase-orders/general.pipeline_staged', ['count' => $card['staged_count']]) }}
                                            @if ($card['received_date']) · {{ $card['received_date'] }}@endif
                                        </div>
                                        <div class="pp-chips">
                                            @if ($card['po_number'])<span class="pp-chip pp-chip-po">{{ $card['po_number'] }}</span>@endif
                                            <span class="pp-chip pp-chip-wait">{{ $card['status'] }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Reconciling --}}
                        <div class="pp-col" data-pp-stage="reconciling" style="--pp-c: var(--pp-reconciling)">
                            <div class="pp-col-cards">
                                @foreach ($pipeline['pendingInvoices'] as $card)
                                    <div class="pp-card" data-pp-modal="invoice-{{ $card['id'] }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $card['invoice_number'] }}</div>
                                        <div class="pp-d">
                                            @if ($card['order_number']){{ $card['order_number'] }} · @endif
                                            <span class="pp-money">{{ $fmt($card['total']) }}</span>
                                        </div>
                                        <div class="pp-chips"><span class="pp-chip pp-chip-wait">{{ trans('admin/purchase-orders/general.invoice_approval_pending') }}</span></div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Completed --}}
                        <div class="pp-col" data-pp-stage="completed" style="--pp-c: var(--pp-completed)">
                            <div class="pp-col-cards">
                                @foreach ($pipeline['completed'] as $card)
                                    <div class="pp-card" data-pp-modal="order-{{ $card['id'] }}" tabindex="0" role="button">
                                        <div class="pp-t">{{ $card['order_number'] }}</div>
                                        <div class="pp-d">
                                            {{ trans('admin/purchase-orders/general.pipeline_items', ['count' => $card['items_count']]) }}
                                            · <span class="pp-money">{{ $fmt($card['total']) }}</span>
                                        </div>
                                        <div class="pp-chips">
                                            <span class="pp-chip pp-chip-done">{{ trans('admin/purchase-orders/general.pipeline_deployed_badge') }}</span>
                                            <span class="pp-chip pp-chip-done">{{ trans('admin/purchase-orders/general.invoice_approval_approved') }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                    </div>
                </div>

                @include('reports.procurement._reports-stream')
            </div>
        </div>
    </div>
</div>

{{-- ═══ Lightbox content: one hidden block per card, cloned into the shared
     Bootstrap modal on click. Self-contained — no extra requests. ═══ --}}
<div class="hidden" id="pp-modal-store">
    @foreach ($pipeline['requisitionCards'] ?? [] as $reqCard)
        <div data-pp-content="reqm-{{ $reqCard['id'] }}" data-pp-color="var(--pp-budgeting)" data-pp-title="{{ $reqCard['number'] }}">
            <div class="pp-facts">
                <span>{{ trans('general.total_cost') }}<b class="pp-money">{{ $fmt($reqCard['total']) }}</b></span>
                <span>{{ trans('admin/orders/general.line_items') }}<b>{{ count($reqCard['items']) }}</b></span>
                <span>{{ trans('general.status') }}<b>{{ ucfirst($reqCard['status']) }}</b></span>
            </div>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('admin/orders/general.item') }}</th>
                            <th class="text-right">{{ trans('general.qty') }}</th>
                            <th class="text-right">{{ trans('admin/orders/general.unit_cost') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reqCard['items'] as $line)
                            <tr>
                                <td>{{ $line['description'] ?: '—' }}</td>
                                <td class="text-right">{{ $line['quantity'] }}</td>
                                <td class="text-right pp-money">{{ $fmt($line['unit_cost']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top:10px;">
                {{-- The builder is where a requisition becomes a PO — that is the work
                     this card is waiting on, so the button goes there, not to the
                     read-only record. --}}
                <a class="btn btn-primary btn-sm" href="{{ route('purchase-orders.builder', ['requisition' => $reqCard['id']]) }}">{{ trans('admin/purchase-orders/general.pipeline_open_requisition') }}</a>
            </div>
        </div>
    @endforeach
    @foreach ($pipeline['sentRequisitionCards'] ?? [] as $sentCard)
        <div data-pp-content="sentreq-{{ $sentCard['id'] }}" data-pp-color="var(--pp-ordering)" data-pp-title="{{ $sentCard['number'] }}">
            <div class="pp-facts">
                <span>{{ trans('general.total_cost') }}<b class="pp-money">{{ $fmt($sentCard['total']) }}</b></span>
                <span>{{ trans('admin/orders/general.line_items') }}<b>{{ count($sentCard['items']) }}</b></span>
                <span>{{ trans('admin/store/general.funding_label') }}<b>{{ $sentCard['account'] }}</b></span>
                <span>{{ trans('admin/purchase-orders/general.vendor_sent_at') }}<b>{{ $sentCard['sent_at'] }}</b></span>
                @if ($sentCard['quote_number'])
                    <span>{{ trans('admin/purchase-orders/general.quote_number') }}<b>{{ $sentCard['quote_number'] }}</b></span>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('admin/orders/general.item') }}</th>
                            <th class="text-right">{{ trans('general.qty') }}</th>
                            <th class="text-right">{{ trans('admin/orders/general.unit_cost') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sentCard['items'] as $line)
                            <tr>
                                <td>{{ $line['description'] ?: '—' }}</td>
                                <td class="text-right">{{ $line['quantity'] }}</td>
                                <td class="text-right pp-money">{{ $fmt($line['unit_cost']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top:10px;">
                <a class="btn btn-primary btn-sm" href="{{ route('purchase-orders.builder', ['requisition' => $sentCard['id']]) }}">{{ trans('admin/purchase-orders/general.pipeline_open_requisition') }}</a>
            </div>
        </div>
    @endforeach
    @foreach ($pipeline['storeQueue'] ?? [] as $queueCard)
        <div data-pp-content="storeq-{{ $queueCard['id'] }}" data-pp-color="var(--pp-ordering)" data-pp-title="{{ $queueCard['number'] }}">
            <div class="pp-facts">
                <span>{{ trans('general.total_cost') }}<b class="pp-money">{{ $fmt($queueCard['total']) }}</b></span>
                <span>{{ trans('admin/orders/general.line_items') }}<b>{{ count($queueCard['items']) }}</b></span>
                <span>{{ trans('admin/store/general.requested_by') }}<b>{{ $queueCard['requester'] }}</b></span>
            </div>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('admin/orders/general.item') }}</th>
                            <th class="text-right">{{ trans('general.qty') }}</th>
                            <th class="text-right">{{ trans('admin/orders/general.unit_cost') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($queueCard['items'] as $line)
                            <tr>
                                <td>{{ $line['description'] ?: '—' }}</td>
                                <td class="text-right">{{ $line['quantity'] }}</td>
                                <td class="text-right pp-money">{{ $fmt($line['unit_cost']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top:10px;">
                <a class="btn btn-primary btn-sm" href="{{ route('procurement.approvals') }}">{{ trans('admin/purchase-orders/general.pipeline_open_queue') }}</a>
            </div>
        </div>
    @endforeach
    @foreach ([['budgeting', $pipeline['planned'], 'planned'], ['ordering', $pipeline['open'], 'order'], ['deploying', $pipeline['processing'], 'order'], ['completed', $pipeline['completed'], 'order']] as [$stageKey, $cards, $prefix])
        @foreach ($cards as $card)
            <div data-pp-content="{{ $prefix }}-{{ $card['id'] }}" data-pp-color="var(--pp-{{ $stageKey }})" data-pp-title="{{ $card['order_number'] }}" data-pp-url="{{ route('orders.show', $card['id']) }}">
                <div class="pp-facts">
                    <span>{{ trans('general.total_cost') }}<b class="pp-money">{{ $fmt($card['total']) }}</b></span>
                    <span>{{ trans('admin/orders/general.line_items') }}<b>{{ $card['items_count'] }}</b></span>
                    @if ($card['po_number'])
                        <span>{{ trans('admin/purchase-orders/general.purchase_order') }}<b>{{ $card['po_number'] }}</b></span>
                    @endif
                </div>
                @if ($prefix === 'planned' && ! $card['po_number'])
                    <p class="text-danger" style="font-size:12px;">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        {{ trans('admin/purchase-orders/general.pipeline_convert_gate_note') }}
                    </p>
                @endif
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>{{ trans('admin/orders/general.item') }}</th>
                                <th>{{ trans('admin/hardware/form.serial') }}</th>
                                <th class="text-right">{{ trans('general.qty') }}</th>
                                <th class="text-right">{{ trans('admin/orders/general.unit_cost') }}</th>
                                <th>{{ trans('general.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($card['items'] as $item)
                                <tr>
                                    <td>{{ $item['item_label'] ?: '—' }}</td>
                                    <td>{{ $item['serial'] ?? '—' }}</td>
                                    <td class="text-right">{{ $item['quantity'] }}</td>
                                    <td class="text-right pp-money">{{ $fmt($item['unit_cost']) }}</td>
                                    <td>
                                        @if ($item['deployed'])
                                            <span class="pp-chip pp-chip-done">{{ trans('admin/purchase-orders/general.pipeline_deployed_badge') }}</span>
                                        @elseif ($item['received_at'])
                                            <span class="pp-chip pp-chip-wait">{{ trans('admin/purchase-orders/general.pipeline_received_badge') }} {{ $item['received_at'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($card['items_count'] > count($card['items']))
                    <p class="text-muted" style="font-size:11.5px;">
                        {{ trans('admin/purchase-orders/general.pipeline_more_cards', ['count' => $card['items_count'] - count($card['items'])]) }}
                    </p>
                @endif
                <div data-pp-actions>
                    <a href="{{ route('orders.show', $card['id']) }}" class="btn btn-primary btn-sm js-lightbox">
                        {{ trans('admin/purchase-orders/general.pipeline_open_order') }}
                    </a>
                </div>
            </div>
        @endforeach
    @endforeach

    @foreach ($pipeline['pendingInvoices'] as $card)
        <div data-pp-content="invoice-{{ $card['id'] }}" data-pp-color="var(--pp-reconciling)" data-pp-title="{{ $card['invoice_number'] }}">
            <div class="pp-facts">
                <span>{{ trans('admin/orders/general.subtotal') }}<b class="pp-money">{{ $fmt($card['subtotal']) }}</b></span>
                <span>{{ trans('general.total_cost') }}<b class="pp-money">{{ $fmt($card['total']) }}</b></span>
                @if ($card['order_number'])
                    <span>{{ trans('admin/orders/general.order') }}<b>{{ $card['order_number'] }}</b></span>
                @endif
                @if ($card['invoice_date'])
                    <span>{{ trans('admin/orders/general.invoice_date') }}<b>{{ $card['invoice_date'] }}</b></span>
                @endif
            </div>
            <div data-pp-actions>
                @can('procurement.edit')
                <form method="post" action="{{ route('reports.procurement.invoice-approval.update', $card['id']) }}" style="display:inline;">
                    @csrf
                    @method('PATCH')
                    <button type="submit" name="approval_status" value="approved" class="btn btn-success btn-sm">
                        {{ trans('admin/purchase-orders/general.invoice_action_approve') }}
                    </button>
                    <button type="submit" name="approval_status" value="disputed" class="btn btn-default btn-sm">
                        {{ trans('admin/purchase-orders/general.invoice_action_dispute') }}
                    </button>
                </form>
                @endcan
                <a href="{{ route('reports.procurement.invoice-approval') }}" class="btn btn-default btn-sm">
                    {{ trans('admin/purchase-orders/general.pipeline_open_invoice_queue') }}
                </a>
            </div>
        </div>
    @endforeach
</div>

@include('partials.pipeline-board.modal', ['stageNames' => collect(array_keys($stages))->mapWithKeys(fn ($k) => [$k => trans('admin/purchase-orders/general.stage_'.$k)])->all()])
