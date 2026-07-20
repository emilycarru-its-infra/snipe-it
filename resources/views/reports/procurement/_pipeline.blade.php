{{-- Procurement pipeline: chevron rail (FY money, stage by stage) and the
     device board (each order/device in exactly one stage). Included from the
     dashboard, so the parent view's variables are in scope: $selectedFy,
     $totalBudget, $plannedTotal, $totalRemaining, $totalCommitted,
     $totalInvoiced, $eolCount, $eolEstimate, $leaseExpiryTotal, $poCount,
     $pendingApprovalCount, $userAgreementsAwaitingSignatureCount,
     $scheduleSigningQueueCount, $pendingDecisionCount and $pipeline (from
     App\Services\ProcurementPipeline).

     Stage colors are a fixed six-slot categorical palette validated for
     adjacent-pair CVD separation and 3:1 surface contrast — don't reorder
     or substitute hues casually. --}}

@php
    // One row per chevron: stage key → the board column + filter value.
    // The rail figure/notes are built here so the markup below stays flat.
    $fmt = fn ($v) => '$'.\App\Helpers\Helper::formatCurrencyOutput($v);
    $stages = [
        'budgeting' => [
            'big' => $fmt($totalBudget),
            'notes' => [
                trans('admin/purchase-orders/general.pipeline_budgeting_note', [
                    'planned' => $fmt($plannedTotal), 'remaining' => $fmt($totalRemaining),
                ]),
                trans('admin/purchase-orders/general.pipeline_budgeting_note2', [
                    'eol_count' => $eolCount, 'eol_cost' => $fmt($eolEstimate), 'lease_cost' => $fmt($leaseExpiryTotal),
                ]),
                ...(($liveCarry ?? null) ? [trans('admin/purchase-orders/general.card_budget_incl_carry', [
                    'amount' => $fmt($liveCarry['unused']), 'source' => $liveCarry['source_fy'],
                ])] : []),
            ],
            'gate' => true,
        ],
        'ordering' => [
            'big' => $fmt($totalCommitted),
            'notes' => [trans('admin/purchase-orders/general.pipeline_ordering_note', [
                'pos' => $poCount, 'orders' => count($pipeline['open']) + $pipeline['openMore'],
            ])],
        ],
        'processing' => [
            'big' => (string) $pipeline['stagedItemCount'],
            'notes' => [trans('admin/purchase-orders/general.pipeline_processing_note', [
                'returns' => count($pipeline['returns']['prep']['cards']) + $pipeline['returns']['prep']['more'],
            ])],
        ],
        'deploying' => [
            'big' => (string) $pipeline['deploying']['total'],
            'notes' => [trans('admin/purchase-orders/general.pipeline_deploying_note', [
                'sent' => $userAgreementsAwaitingSignatureCount,
            ])],
        ],
        'reconciling' => [
            'big' => $fmt($totalInvoiced),
            'notes' => [trans('admin/purchase-orders/general.pipeline_reconciling_note', [
                'pending' => $pendingApprovalCount, 'schedules' => $scheduleSigningQueueCount,
            ])],
        ],
        'completed' => [
            'big' => (string) $pipeline['completedCount'],
            'notes' => [trans('admin/purchase-orders/general.pipeline_completed_note')],
        ],
    ];
@endphp

<style>
    .proc-pipe {
        --pp-budgeting: #8a63d2;
        --pp-ordering: #1f5f99;
        --pp-processing: #1f9e8e;
        --pp-deploying: #c8860a;
        --pp-reconciling: #b05c9e;
        --pp-completed: #4e9b52;
    }
    .pp-rail-scroll { overflow-x: auto; }
    .pp-rail { display: flex; min-width: 1080px; padding: 2px 0; }
    .pp-chev {
        flex: 1 1 0; position: relative; padding: 10px 14px 26px 28px; min-height: 112px;
        cursor: pointer;
        clip-path: polygon(0 0, calc(100% - 16px) 0, 100% 50%, calc(100% - 16px) 100%, 0 100%, 16px 50%);
        background: color-mix(in srgb, var(--pp-c) 9%, #fff);
        margin-right: -11px;
    }
    .pp-chev:first-child {
        clip-path: polygon(0 0, calc(100% - 16px) 0, 100% 50%, calc(100% - 16px) 100%, 0 100%);
        padding-left: 16px;
    }
    .pp-chev:focus-visible { outline: 2px solid var(--pp-c); outline-offset: -3px; }
    .pp-chev.active, .pp-chev.selected { background: var(--pp-c); }
    .pp-rail.filtering .pp-chev:not(.selected) { background: color-mix(in srgb, var(--pp-c) 5%, #fff); opacity: .55; }
    .pp-chev .pp-stage { font-size: 11.5px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: var(--pp-c); }
    .pp-chev .pp-months { font-size: 10.5px; color: #97a2ad; }
    .pp-chev .pp-big { font-size: 19px; font-weight: 700; margin-top: 5px; font-variant-numeric: tabular-nums; color: #333; }
    .pp-chev .pp-note { font-size: 11px; color: #62707e; margin-top: 1px; }
    .pp-chev.active .pp-stage, .pp-chev.active .pp-big,
    .pp-chev.selected .pp-stage, .pp-chev.selected .pp-big { color: #fff; }
    .pp-chev.active .pp-months, .pp-chev.active .pp-note,
    .pp-chev.selected .pp-months, .pp-chev.selected .pp-note { color: rgba(255,255,255,.85); }
    .pp-rail.filtering .pp-chev:not(.selected) .pp-stage { color: var(--pp-c); }
    .pp-rail.filtering .pp-chev:not(.selected) .pp-big { color: #333; }
    .pp-rail.filtering .pp-chev:not(.selected) .pp-months,
    .pp-rail.filtering .pp-chev:not(.selected) .pp-note { color: #62707e; }
    .pp-gate {
        position: absolute; bottom: 7px; left: 28px; font-size: 9.5px; font-weight: 700;
        letter-spacing: .06em; text-transform: uppercase; color: #c0392b;
    }
    .pp-caption { font-size: 12px; color: #97a2ad; margin: 8px 0 0; }

    .pp-board-scroll { overflow-x: auto; }
    .pp-board { display: grid; grid-template-columns: repeat(6, minmax(188px, 1fr)); gap: 10px; min-width: 1080px; }
    .pp-col { min-width: 0; }
    .pp-col-head {
        border-top: 3px solid var(--pp-c); background: color-mix(in srgb, var(--pp-c) 5%, #fff);
        border-radius: 3px; padding: 6px 9px; display: flex; align-items: baseline;
        box-shadow: 0 1px 1px rgba(0,0,0,.08); margin-bottom: 8px; cursor: pointer;
    }
    .pp-col-head:focus-visible { outline: 2px solid var(--pp-c); outline-offset: 1px; }
    .pp-col-head .pp-name { font-weight: 700; font-size: 12.5px; }
    .pp-col-head .pp-count {
        margin-left: auto; font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums;
        background: color-mix(in srgb, var(--pp-c) 15%, #fff); color: var(--pp-c);
        border-radius: 9px; padding: 0 7px;
    }
    .pp-card {
        background: #fff; border: 1px solid #e4e9ee; border-radius: 4px;
        padding: 8px 9px 7px; box-shadow: 0 1px 1px rgba(0,0,0,.08); margin-bottom: 8px;
        transition: border-color .12s ease;
    }
    .pp-card[data-pp-modal] { cursor: pointer; }
    .pp-card[data-pp-modal]:hover { border-color: var(--pp-c); }
    .pp-card[data-pp-modal]:focus-visible { outline: 2px solid var(--pp-c); outline-offset: 1px; }
    .pp-card .pp-t { font-weight: 600; font-size: 12.5px; line-height: 1.3; }
    .pp-card .pp-d { font-size: 11px; color: #62707e; margin-top: 2px; }
    .pp-card.pp-empty {
        border-style: dashed; color: #97a2ad; font-size: 11.5px; text-align: center;
        padding: 14px 8px; box-shadow: none; background: transparent;
    }
    .pp-money { font-variant-numeric: tabular-nums; }
    .pp-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
    .pp-chip {
        font-size: 9.5px; font-weight: 700; letter-spacing: .04em; border-radius: 3px;
        padding: 1px 5px; text-transform: uppercase; white-space: nowrap; display: inline-block;
    }
    .pp-chip-po { background: color-mix(in srgb, var(--pp-ordering) 14%, #fff); color: var(--pp-ordering); }
    .pp-chip-inv { background: color-mix(in srgb, var(--pp-reconciling) 14%, #fff); color: var(--pp-reconciling); }
    .pp-chip-need { background: rgba(192,57,43,.12); color: #c0392b; }
    .pp-chip-wait { background: rgba(185,122,8,.14); color: #b97a08; }
    .pp-chip-done { background: rgba(61,139,65,.14); color: #3d8b41; }
    .pp-col-def { font-size: 10.5px; color: #97a2ad; line-height: 1.4; padding: 0 2px; }
    .pp-more { font-size: 11px; color: #62707e; padding: 2px; }

    .pp-returns { border-top: 1px solid #e4e9ee; margin-top: 6px; padding-top: 10px; }
    .pp-returns h4 { font-size: 12.5px; margin: 0 0 6px; color: #62707e; font-weight: 700; }
    .pp-ret-grid { display: grid; grid-template-columns: repeat(3, minmax(200px, 1fr)); gap: 10px; min-width: 640px; }
    .pp-lname {
        font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
        color: #97a2ad; border-bottom: 2px solid #d2d6de; padding-bottom: 3px; margin-bottom: 6px;
        display: flex;
    }
    .pp-lname .pp-lcount { margin-left: auto; font-variant-numeric: tabular-nums; }

    .pp-filter-note {
        font-size: 11px; color: #62707e; padding: 6px 12px; border-bottom: 1px solid #e4e9ee;
        display: none; align-items: center; gap: 6px;
    }
    .pp-filter-note.show { display: flex; }
    .pp-filter-note .pp-fdot { width: 8px; height: 8px; border-radius: 2px; }
    .pp-filter-note a { margin-left: auto; }
    .pp-sdot { width: 7px; height: 7px; border-radius: 2px; display: inline-block; margin-right: 6px; vertical-align: 1px; }

    #ppModal .modal-header { border-top: 3px solid var(--pp-mc, #3c8dbc); }
    #ppModal .pp-facts { display: flex; flex-wrap: wrap; gap: 18px; font-size: 12px; color: #62707e; margin-bottom: 10px; }
    #ppModal .pp-facts b { display: block; font-size: 14.5px; color: #333; font-variant-numeric: tabular-nums; }
    #ppModal .table { margin-bottom: 0; }
</style>

{{-- ═══ Chevron rail ═══ --}}
<div class="row">
    <div class="col-md-12">
        <div class="box box-default proc-pipe">
            <div class="box-header with-border">
                <h3 class="box-title">
                    {{ $selectedFy
                        ? trans('admin/purchase-orders/general.pipeline_title', ['fy' => $selectedFy])
                        : trans('admin/purchase-orders/general.pipeline_title_all') }}
                </h3>
                @can('budget_allocations.manage')
                    <a href="#" data-toggle="modal" data-target="#budgetAllocationsModal" class="btn btn-default btn-xs pull-right">
                        {{ trans('admin/budget-allocations/general.allocations') }}
                    </a>
                @endcan
            </div>
            <div class="box-body">
                <div class="pp-rail-scroll">
                    <div class="pp-rail" id="pp-rail">
                        @foreach ($stages as $key => $stage)
                            <div class="pp-chev {{ $pipeline['activeStage'] === $key ? 'active' : '' }}"
                                 style="--pp-c: var(--pp-{{ $key }})"
                                 data-pp-stage="{{ $key }}" tabindex="0" role="button" aria-pressed="false">
                                <div class="pp-stage">{{ trans('admin/purchase-orders/general.stage_'.$key) }}</div>
                                <div class="pp-months">{{ trans('admin/purchase-orders/general.stage_'.$key.'_months') }}</div>
                                <div class="pp-big">{{ $stage['big'] }}</div>
                                @foreach ($stage['notes'] as $note)
                                    <div class="pp-note">{{ $note }}</div>
                                @endforeach
                                @if ($stage['gate'] ?? false)
                                    <div class="pp-gate">
                                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                                        {{ trans('admin/purchase-orders/general.pipeline_gate') }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
                <p class="pp-caption">{{ trans('admin/purchase-orders/general.pipeline_hint') }}</p>
            </div>
        </div>
    </div>
</div>

{{-- ═══ Device board ═══ --}}
<div class="row">
    <div class="col-md-12">
        <div class="box box-default proc-pipe">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.pipeline_board_title') }}</h3>
                <span class="text-muted" style="font-size:12px; margin-left:10px;">
                    {{ trans('admin/purchase-orders/general.pipeline_board_hint') }}
                </span>
            </div>
            <div class="box-body">
                <div class="pp-board-scroll">
                    <div class="pp-board">

                        {{-- Budgeting --}}
                        <div class="pp-col" style="--pp-c: var(--pp-budgeting)">
                            <div class="pp-col-head" data-pp-stage="budgeting" tabindex="0" role="button" aria-pressed="false">
                                <span class="pp-name">{{ trans('admin/purchase-orders/general.stage_budgeting') }}</span>
                                <span class="pp-count">{{ count($pipeline['planned']) + $pipeline['plannedMore'] }}</span>
                            </div>
                            @forelse ($pipeline['planned'] as $card)
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
                            @empty
                                <div class="pp-card pp-empty">{{ trans('admin/purchase-orders/general.pipeline_empty_column') }}</div>
                            @endforelse
                            @if ($pipeline['plannedMore'])
                                <div class="pp-more">{{ trans('admin/purchase-orders/general.pipeline_more_cards', ['count' => $pipeline['plannedMore']]) }}</div>
                            @endif
                            <div class="pp-col-def">{{ trans('admin/purchase-orders/general.pipeline_col_budgeting_def') }}</div>
                        </div>

                        {{-- Ordering --}}
                        <div class="pp-col" style="--pp-c: var(--pp-ordering)">
                            <div class="pp-col-head" data-pp-stage="ordering" tabindex="0" role="button" aria-pressed="false">
                                <span class="pp-name">{{ trans('admin/purchase-orders/general.stage_ordering') }}</span>
                                <span class="pp-count">{{ count($pipeline['open']) + $pipeline['openMore'] }}</span>
                            </div>
                            @forelse ($pipeline['open'] as $card)
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
                            @empty
                                <div class="pp-card pp-empty">{{ trans('admin/purchase-orders/general.pipeline_empty_column') }}</div>
                            @endforelse
                            @if ($pipeline['openMore'])
                                <div class="pp-more">{{ trans('admin/purchase-orders/general.pipeline_more_cards', ['count' => $pipeline['openMore']]) }}</div>
                            @endif
                            <div class="pp-col-def">{{ trans('admin/purchase-orders/general.pipeline_col_ordering_def') }}</div>
                        </div>

                        {{-- Processing --}}
                        <div class="pp-col" style="--pp-c: var(--pp-processing)">
                            <div class="pp-col-head" data-pp-stage="processing" tabindex="0" role="button" aria-pressed="false">
                                <span class="pp-name">{{ trans('admin/purchase-orders/general.stage_processing') }}</span>
                                <span class="pp-count">{{ $pipeline['stagedItemCount'] }}</span>
                            </div>
                            @forelse ($pipeline['processing'] as $card)
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
                            @empty
                                <div class="pp-card pp-empty">{{ trans('admin/purchase-orders/general.pipeline_empty_column') }}</div>
                            @endforelse
                            @if ($pipeline['processingMore'])
                                <div class="pp-more">{{ trans('admin/purchase-orders/general.pipeline_more_cards', ['count' => $pipeline['processingMore']]) }}</div>
                            @endif
                            <div class="pp-col-def">{{ trans('admin/purchase-orders/general.pipeline_col_processing_def') }}</div>
                        </div>

                        {{-- Deploying --}}
                        <div class="pp-col" style="--pp-c: var(--pp-deploying)">
                            <div class="pp-col-head" data-pp-stage="deploying" tabindex="0" role="button" aria-pressed="false">
                                <span class="pp-name">{{ trans('admin/purchase-orders/general.stage_deploying') }}</span>
                                <span class="pp-count">{{ $pipeline['deploying']['total'] }}</span>
                            </div>
                            @if ($pipeline['deploying']['total'] > 0)
                                <a href="{{ route('reports.procurement.user-agreement-ledger') }}" style="text-decoration:none; color:inherit;">
                                    <div class="pp-card">
                                        <div class="pp-t">{{ trans('admin/purchase-orders/general.report_user_agreement_ledger') }}</div>
                                        <div class="pp-d">
                                            @if ($pipeline['deploying']['quoted'])
                                                {{ trans('admin/purchase-orders/general.pipeline_agreements_quoted', ['count' => $pipeline['deploying']['quoted']]) }}<br>
                                            @endif
                                            @if ($pipeline['deploying']['sent'])
                                                {{ trans('admin/purchase-orders/general.pipeline_agreements_sent', ['count' => $pipeline['deploying']['sent']]) }}<br>
                                            @endif
                                            @if ($pipeline['deploying']['signed'])
                                                {{ trans('admin/purchase-orders/general.pipeline_agreements_signed', ['count' => $pipeline['deploying']['signed']]) }}
                                            @endif
                                        </div>
                                        <div class="pp-chips"><span class="pp-chip pp-chip-wait">agreement_sent</span></div>
                                    </div>
                                </a>
                            @else
                                <div class="pp-card pp-empty">{{ trans('admin/purchase-orders/general.pipeline_empty_column') }}</div>
                            @endif
                            <div class="pp-col-def">{{ trans('admin/purchase-orders/general.pipeline_col_deploying_def') }}</div>
                        </div>

                        {{-- Reconciling --}}
                        <div class="pp-col" style="--pp-c: var(--pp-reconciling)">
                            <div class="pp-col-head" data-pp-stage="reconciling" tabindex="0" role="button" aria-pressed="false">
                                <span class="pp-name">{{ trans('admin/purchase-orders/general.stage_reconciling') }}</span>
                                <span class="pp-count">{{ count($pipeline['pendingInvoices']) + $pipeline['pendingInvoicesMore'] }}</span>
                            </div>
                            @forelse ($pipeline['pendingInvoices'] as $card)
                                <div class="pp-card" data-pp-modal="invoice-{{ $card['id'] }}" tabindex="0" role="button">
                                    <div class="pp-t">{{ $card['invoice_number'] }}</div>
                                    <div class="pp-d">
                                        @if ($card['order_number']){{ $card['order_number'] }} · @endif
                                        <span class="pp-money">{{ $fmt($card['total']) }}</span>
                                    </div>
                                    <div class="pp-chips"><span class="pp-chip pp-chip-wait">{{ trans('admin/purchase-orders/general.invoice_approval_pending') }}</span></div>
                                </div>
                            @empty
                                <div class="pp-card pp-empty">{{ trans('admin/purchase-orders/general.pipeline_empty_column') }}</div>
                            @endforelse
                            @if ($pipeline['pendingInvoicesMore'])
                                <div class="pp-more">{{ trans('admin/purchase-orders/general.pipeline_more_cards', ['count' => $pipeline['pendingInvoicesMore']]) }}</div>
                            @endif
                            <div class="pp-col-def">{{ trans('admin/purchase-orders/general.pipeline_col_reconciling_def') }}</div>
                        </div>

                        {{-- Completed --}}
                        <div class="pp-col" style="--pp-c: var(--pp-completed)">
                            <div class="pp-col-head" data-pp-stage="completed" tabindex="0" role="button" aria-pressed="false">
                                <span class="pp-name">{{ trans('admin/purchase-orders/general.stage_completed') }}</span>
                                <span class="pp-count">{{ $pipeline['completedCount'] }}</span>
                            </div>
                            @forelse ($pipeline['completed'] as $card)
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
                            @empty
                                <div class="pp-card pp-empty">{{ trans('admin/purchase-orders/general.pipeline_empty_column') }}</div>
                            @endforelse
                            @if ($pipeline['completedMore'])
                                <div class="pp-more">{{ trans('admin/purchase-orders/general.pipeline_more_cards', ['count' => $pipeline['completedMore']]) }}</div>
                            @endif
                            <div class="pp-col-def">{{ trans('admin/purchase-orders/general.pipeline_col_completed_def') }}</div>
                        </div>

                    </div>
                </div>

                {{-- Returns lane — the reverse pipeline --}}
                <div class="pp-returns">
                    <h4>{{ trans('admin/purchase-orders/general.pipeline_returns_title') }}</h4>
                    <div class="pp-board-scroll">
                        <div class="pp-ret-grid" style="--pp-c: var(--pp-processing)">
                            @foreach (['pending', 'prep', 'closed'] as $lane)
                                <div>
                                    <div class="pp-lname">
                                        {{ trans('admin/purchase-orders/general.pipeline_returns_'.$lane) }}
                                        <span class="pp-lcount">{{ count($pipeline['returns'][$lane]['cards']) + $pipeline['returns'][$lane]['more'] }}</span>
                                    </div>
                                    @forelse ($pipeline['returns'][$lane]['cards'] as $row)
                                        <a href="{{ route('reports.procurement.lease-decisions') }}" style="text-decoration:none; color:inherit;">
                                            <div class="pp-card">
                                                <div class="pp-t">{{ $row['contract_reference'] }}</div>
                                                <div class="pp-d">
                                                    {{ trans('admin/lease-decisions/general.type_'.$row['decision_type']) }}
                                                    @if ($row['decision_date']) · {{ $row['decision_date'] }}@endif
                                                </div>
                                            </div>
                                        </a>
                                    @empty
                                        <div class="pp-card pp-empty">—</div>
                                    @endforelse
                                    @if ($pipeline['returns'][$lane]['more'])
                                        <div class="pp-more">{{ trans('admin/purchase-orders/general.pipeline_more_cards', ['count' => $pipeline['returns'][$lane]['more']]) }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ═══ Lightbox content: one hidden block per card, cloned into the shared
     Bootstrap modal on click. Self-contained — no extra requests. ═══ --}}
<div class="hidden" id="pp-modal-store">
    @foreach ([['budgeting', $pipeline['planned'], 'planned'], ['ordering', $pipeline['open'], 'order'], ['processing', $pipeline['processing'], 'order'], ['completed', $pipeline['completed'], 'order']] as [$stageKey, $cards, $prefix])
        @foreach ($cards as $card)
            <div data-pp-content="{{ $prefix }}-{{ $card['id'] }}" data-pp-color="var(--pp-{{ $stageKey }})" data-pp-title="{{ $card['order_number'] }}">
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
                                <th>{{ trans('admin/orders/general.description') }}</th>
                                <th>{{ trans('admin/hardware/form.serial') }}</th>
                                <th class="text-right">{{ trans('general.qty') }}</th>
                                <th class="text-right">{{ trans('admin/orders/general.unit_cost') }}</th>
                                <th>{{ trans('general.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($card['items'] as $item)
                                <tr>
                                    <td>{{ $item['description'] }}</td>
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
                    <a href="{{ route('orders.show', $card['id']) }}" class="btn btn-primary btn-sm">
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
<div class="modal fade" id="ppModal" tabindex="-1" role="dialog" aria-hidden="true">
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
    // Card lightbox: clone the card's hidden content block into the shared
    // Bootstrap modal. No fetches — everything is rendered server-side.
    (function () {
        var store = document.getElementById('pp-modal-store');
        if (! store) { return; }

        function openCard(key) {
            var content = store.querySelector('[data-pp-content="' + key + '"]');
            if (! content) { return; }
            document.getElementById('ppModalTitle').textContent = content.dataset.ppTitle;
            var body = document.getElementById('ppModalBody');
            body.innerHTML = '';
            Array.prototype.forEach.call(content.children, function (child) {
                body.appendChild(child.cloneNode(true));
            });
            document.querySelector('#ppModal .modal-header').style.setProperty('--pp-mc', content.dataset.ppColor);
            $('#ppModal').modal('show');
        }

        document.querySelectorAll('.pp-card[data-pp-modal]').forEach(function (el) {
            el.addEventListener('click', function () { openCard(el.dataset.ppModal); });
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openCard(el.dataset.ppModal); }
            });
        });
    })();

    // Stage filter: a chevron (or board column header) narrows the report
    // jump-nav and inline report boxes to that stage; same control again —
    // or the "show all" link — clears it.
    (function () {
        var rail = document.getElementById('pp-rail');
        if (! rail) { return; }
        var current = null;
        var stageNames = {!! json_encode(collect(array_keys($stages))->mapWithKeys(fn ($k) => [$k => trans('admin/purchase-orders/general.stage_'.$k)])) !!};

        function apply(stage) {
            current = (current === stage) ? null : stage;
            rail.classList.toggle('filtering', !! current);
            rail.querySelectorAll('.pp-chev').forEach(function (chev) {
                var selected = current && chev.dataset.ppStage === current;
                chev.classList.toggle('selected', !! selected);
                chev.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            document.querySelectorAll('[data-report-stage]').forEach(function (el) {
                el.classList.toggle('hidden', !! current && el.dataset.reportStage !== current);
            });
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

        document.querySelectorAll('[data-pp-stage]').forEach(function (el) {
            el.addEventListener('click', function () { apply(el.dataset.ppStage); });
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); apply(el.dataset.ppStage); }
            });
        });
        var clear = document.getElementById('pp-filter-clear');
        if (clear) {
            clear.addEventListener('click', function (e) { e.preventDefault(); apply(current); });
        }
    })();
</script>
