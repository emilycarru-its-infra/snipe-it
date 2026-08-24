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

<style>
    /* Theme tokens — light-dark() follows the app's html[data-theme]. The
       stage hues are separately validated palettes per theme, not tints of
       one another. */
    .proc-pipe {
        --pp-budgeting: light-dark(#8a63d2, #9877e0);
        --pp-ordering: light-dark(#1f5f99, #2e6fa8);
        /* Processing merged into Deploying; the teal now colors only the
           returns lane (the reverse pipeline) below the board. */
        --pp-processing: light-dark(#1f9e8e, #25a392);
        --pp-deploying: light-dark(#e39a13, #e3a72e);
        --pp-reconciling: light-dark(#b05c9e, #bc64a8);
        --pp-completed: light-dark(#4e9b52, #57a05b);
        --pp-surface: light-dark(#ffffff, #22272e);
        --pp-ink: light-dark(#333a40, #e6eaf0);
        --pp-ink2: light-dark(#62707e, #a7b0bc);
        --pp-ink3: light-dark(#97a2ad, #778290);
        --pp-line: light-dark(#e4e9ee, #3a424b);
        --pp-line-strong: light-dark(#d2d6de, #4a545f);
        --pp-bad: light-dark(#c0392b, #d66557);
        --pp-warn: light-dark(#b97a08, #d09a2e);
        --pp-ok: light-dark(#3d8b41, #5cb160);
    }
    .pp-rail-scroll { overflow-x: auto; }
    .pp-rail { display: flex; min-width: 1080px; padding: 2px 0 0; }
    {{-- Chevron widths are tuned so the 5px notch gap at each junction sits
         CENTERED on its board column boundary: everyone after the first is
         pushed 2.5px right (first +13.5px, middles +11px, last -2.5px with
         the 11px overlap margins), and the last box ends flush with the
         container (no horizontal overflow). --}}
    .pp-chev {
        flex: 0 0 calc(20% + 11px); position: relative; padding: 12px 16px 30px 30px;
        cursor: pointer;
        clip-path: polygon(0 0, calc(100% - 16px) 0, 100% 50%, calc(100% - 16px) 100%, 0 100%, 16px 50%);
        background: color-mix(in srgb, var(--pp-c) 10%, var(--pp-surface));
        margin-right: -11px;
    }
    .pp-chev:first-child {
        flex-basis: calc(20% + 13.5px);
        clip-path: polygon(0 0, calc(100% - 16px) 0, 100% 50%, calc(100% - 16px) 100%, 0 100%);
        padding-left: 18px;
    }
    .pp-chev:last-child { flex-basis: calc(20% - 2.5px); margin-right: 0; }
    .pp-chev:focus-visible { outline: 2px solid var(--pp-c); outline-offset: -3px; }
    .pp-chev.selected { background: var(--pp-c); }
    .pp-rail.filtering .pp-chev:not(.selected) { background: color-mix(in srgb, var(--pp-c) 5%, var(--pp-surface)); opacity: .55; }
    .pp-chev .pp-stage { font-size: 12.5px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: var(--pp-c); }
    .pp-chev .pp-count {
        font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: 0;
        background: color-mix(in srgb, var(--pp-c) 16%, var(--pp-surface)); color: var(--pp-c);
        border-radius: 9px; padding: 0 7px; margin-left: 5px; vertical-align: 1px;
    }
    .pp-chev.selected .pp-count { background: rgba(255,255,255,.25); color: #fff; }
    .pp-chev .pp-def { color: var(--pp-ink3); font-size: 11px; }
    .pp-chev .pp-big { font-size: 22px; font-weight: 700; margin: 6px 0 4px; font-variant-numeric: tabular-nums; color: var(--pp-ink); }
    .pp-chev .pp-note { font-size: 12.5px; color: var(--pp-ink2); margin-top: 2px; line-height: 1.4; }
    /* The chevron doubles as a filter toggle; the golink is the one part
       of it that navigates instead (the JS handler skips anchor clicks). */
    .pp-chev .pp-golink { display: block; color: var(--pp-c); font-weight: 600; text-decoration: none; }
    .pp-chev .pp-golink:hover, .pp-chev .pp-golink:focus { text-decoration: underline; }
    .pp-chev.selected .pp-golink { color: #fff; }
    .pp-chev.selected .pp-stage, .pp-chev.selected .pp-big { color: #fff; }
    .pp-chev.selected .pp-note { color: rgba(255,255,255,.85); }
    .pp-rail.filtering .pp-chev:not(.selected) .pp-stage { color: var(--pp-c); }
    .pp-rail.filtering .pp-chev:not(.selected) .pp-big { color: var(--pp-ink); }
    .pp-rail.filtering .pp-chev:not(.selected) .pp-note { color: var(--pp-ink2); }

    .pp-board-scroll { overflow-x: auto; }
    .pp-board { display: grid; grid-template-columns: repeat(5, minmax(200px, 1fr)); gap: 0; min-width: 1080px; }
    .pp-col { min-width: 0; padding: 10px 10px 0; position: relative; }
    {{-- No card is ever withheld, so a busy stage scrolls inside its own
         column instead of stretching the whole board past the fold. --}}
    .pp-col-cards { max-height: 62vh; overflow-y: auto; }
    .pp-col:first-child { padding-left: 0; }
    .pp-col:last-child { padding-right: 0; }
    {{-- The fifth-line hangs 8px clear of the chevron bottoms instead of
         touching them. --}}
    .pp-col + .pp-col::before {
        content: ""; position: absolute; top: 8px; bottom: 0; left: 0;
        width: 1px; background: var(--pp-line); pointer-events: none;
    }
    {{-- Filtering never moves anything: every column keeps its fifth,
         the other stages just empty out. --}}
    .pp-col.pp-col-muted .pp-card { display: none; }
    .pp-col-head {
        border-top: 3px solid var(--pp-c); background: color-mix(in srgb, var(--pp-c) 6%, var(--pp-surface));
        border-radius: 3px; padding: 6px 9px; display: flex; align-items: baseline;
        box-shadow: 0 1px 1px rgba(0,0,0,.08); margin-bottom: 4px; cursor: pointer;
    }
    .pp-col-head:focus-visible { outline: 2px solid var(--pp-c); outline-offset: 1px; }
    .pp-col-head .pp-name { font-weight: 700; font-size: 12.5px; color: var(--pp-ink); }
    .pp-col-head .pp-count {
        margin-left: auto; font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums;
        background: color-mix(in srgb, var(--pp-c) 16%, var(--pp-surface)); color: var(--pp-c);
        border-radius: 9px; padding: 0 7px;
    }
    .pp-col-def { font-size: 11px; color: var(--pp-ink3); line-height: 1.4; padding: 0 2px; margin-bottom: 8px; }
    .pp-card {
        background: var(--pp-surface); border: 1px solid var(--pp-line); border-radius: 4px;
        padding: 8px 9px 7px; box-shadow: 0 1px 1px rgba(0,0,0,.08); margin-bottom: 8px;
        transition: border-color .12s ease;
    }
    .pp-card[data-pp-modal] { cursor: pointer; }
    .pp-card[data-pp-modal]:hover { border-color: var(--pp-c); }
    .pp-card[data-pp-modal]:focus-visible { outline: 2px solid var(--pp-c); outline-offset: 1px; }
    .pp-card .pp-t { font-weight: 600; font-size: 12.5px; line-height: 1.3; color: var(--pp-ink); }
    .pp-card .pp-d { font-size: 11px; color: var(--pp-ink2); margin-top: 2px; }
    .pp-money { font-variant-numeric: tabular-nums; }
    .pp-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
    .pp-chip {
        font-size: 9.5px; font-weight: 700; letter-spacing: .04em; border-radius: 3px;
        padding: 1px 5px; text-transform: uppercase; white-space: nowrap; display: inline-block;
    }
    .pp-chip-po { background: color-mix(in srgb, var(--pp-ordering) 15%, var(--pp-surface)); color: var(--pp-ordering); }
    .pp-chip-need { background: color-mix(in srgb, var(--pp-bad) 14%, var(--pp-surface)); color: var(--pp-bad); }
    .pp-chip-wait { background: color-mix(in srgb, var(--pp-warn) 15%, var(--pp-surface)); color: var(--pp-warn); }
    .pp-chip-done { background: color-mix(in srgb, var(--pp-ok) 15%, var(--pp-surface)); color: var(--pp-ok); }


    .pp-filter-note {
        font-size: 11px; color: var(--pp-ink2); padding: 6px 12px; border-bottom: 1px solid var(--pp-line);
        display: none; align-items: center; gap: 6px;
    }
    .pp-filter-note.show { display: flex; }
    .pp-filter-note .pp-fdot { width: 8px; height: 8px; border-radius: 2px; }
    .pp-filter-note a { margin-left: auto; }
    .pp-sdot { width: 7px; height: 7px; border-radius: 2px; display: inline-block; margin-right: 6px; vertical-align: 1px; }
    #pp-filter-clear-top { display: none; }
    #pp-filter-clear-top.show { display: inline-block; }

    #ppModal .pp-facts { display: flex; flex-wrap: wrap; gap: 18px; font-size: 12px; color: var(--pp-ink2); margin-bottom: 10px; }
    #ppModal .pp-facts b { display: block; font-size: 14.5px; color: var(--pp-ink); font-variant-numeric: tabular-nums; }
    #ppModal .table { margin-bottom: 0; }
</style>

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
                                            @if (($queueCard['status'] ?? 'pending') === 'approved')
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
                <a href="{{ route('reports.procurement.invoice-approval') }}" class="btn btn-default btn-sm">
                    {{ trans('admin/purchase-orders/general.pipeline_open_invoice_queue') }}
                </a>
            </div>
        </div>
    @endforeach
</div>

{{-- Shared modal shell --}}
<div class="modal fade proc-pipe" id="ppModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ trans('general.close') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="ppModalTitle"></h4>
            </div>
            <div class="modal-body" id="ppModalBody"></div>
        </div>
    </div>
</div>

<script nonce="{{ csrf_token() }}">
    // Board filter: hide cards whose text doesn't match; columns keep
    // their headers so the stage rail stays readable while filtering.
    (function () {
        var input = document.getElementById('pp-board-filter');
        if (! input) { return; }
        input.addEventListener('input', function () {
            var term = input.value.trim().toLowerCase();
            document.querySelectorAll('.pp-board .pp-card').forEach(function (card) {
                card.style.display = (term === '' || card.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
            });
        });
    })();

    // Card lightbox: clone the card's hidden content block into the shared
    // Bootstrap modal.
    (function () {
        var store = document.getElementById('pp-modal-store');
        var title = document.getElementById('ppModalTitle');
        var body = document.getElementById('ppModalBody');

        function showModal(color) {
            document.querySelector('#ppModal .modal-header').style.setProperty('--pp-mc', color || '#3c8dbc');
            $('#ppModal').modal('show');
        }

        function openCard(key) {
            var content = store && store.querySelector('[data-pp-content="' + key + '"]');
            if (! content) { return; }
            // Cards backed by a full record page (orders) open that page in
            // the app lightbox — the complete order view (summary, line
            // items, shipments, invoices), not a reduced summary.
            if (content.dataset.ppUrl && window.appLightbox) {
                window.appLightbox.open(content.dataset.ppUrl);
                return;
            }
            title.textContent = content.dataset.ppTitle;
            body.innerHTML = '';
            Array.prototype.forEach.call(content.children, function (child) {
                body.appendChild(child.cloneNode(true));
            });
            showModal(content.dataset.ppColor);
        }

        document.querySelectorAll('.pp-card[data-pp-modal]').forEach(function (el) {
            var open = function () { openCard(el.dataset.ppModal); };
            el.addEventListener('click', open);
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
            });
        });
    })();

    // Stage filter: a chevron (or board column header) narrows the report
    // jump-nav and inline report boxes to that stage; same control again —
    // or either clear link — clears it.
    (function () {
        var rail = document.getElementById('pp-rail');
        if (! rail) { return; }
        var current = null;
        var stageNames = {!! json_encode(collect(array_keys($stages))->mapWithKeys(fn ($k) => [$k => trans('admin/purchase-orders/general.stage_'.$k)])) !!};

        function apply(stage) {
            current = (current === stage) ? null : stage;
            window.ppCurrentStage = current;
            // A stage change starts a fresh slice: any pill refinement from
            // the previous stage is cleared so the whole stage shows.
            if (window.prClearPills) { window.prClearPills(); }
            if (window.prSyncHash) { window.prSyncHash(); }
            rail.classList.toggle('filtering', !! current);
            rail.querySelectorAll('.pp-chev').forEach(function (chev) {
                var selected = current && chev.dataset.ppStage === current;
                chev.classList.toggle('selected', !! selected);
                chev.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            document.querySelectorAll('[data-report-stage]').forEach(function (el) {
                // Pill columns hold their fifth — only their pills hide.
                if (el.classList.contains('pr-pill-col')) {
                    el.classList.toggle('pr-col-muted', !! current && el.dataset.reportStage !== current);

                    return;
                }
                el.classList.toggle('hidden', !! current && el.dataset.reportStage !== current);
            });
            // The Orders Pipeline filters in place: columns never move,
            // non-selected stages just empty out.
            var board = document.querySelector('.pp-board');
            if (board) {
                board.querySelectorAll('.pp-col').forEach(function (col) {
                    col.classList.toggle('pp-col-muted', !! current && col.dataset.ppStage !== current);
                });
            }
            var clearTop = document.getElementById('pp-filter-clear-top');
            if (clearTop) { clearTop.classList.toggle('show', !! current); }
            var note = document.getElementById('pp-filter-note');
            if (note) {
                note.classList.toggle('show', !! current);
                if (current) {
                    note.querySelector('.pp-fdot').style.background = 'var(--pp-' + current + ')';
                    note.querySelector('[data-pp-filter-label]').textContent =
                        {!! json_encode(trans('admin/purchase-orders/general.pipeline_filter_showing', ['stage' => '__STAGE__'])) !!}
                            .replace('__STAGE__', stageNames[current]);
                }
            }
        }

        document.querySelectorAll('.pp-chev[data-pp-stage]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                // Links inside a chevron (the Deployments golink) navigate;
                // they must not also toggle the stage filter.
                if (e.target.closest('a')) { return; }
                apply(el.dataset.ppStage);
            });
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); apply(el.dataset.ppStage); }
            });
        });
        ['pp-filter-clear', 'pp-filter-clear-top'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('click', function (e) { e.preventDefault(); apply(current); });
            }
        });
    })();
</script>
