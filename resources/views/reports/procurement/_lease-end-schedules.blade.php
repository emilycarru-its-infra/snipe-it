{{-- Lease schedules ending in the selected FY — the budgeting-time
     decision list (buyout / return / extend per schedule, with the
     retained-note editor). Lives in Procurement Reports under Budgeting;
     also served standalone and as an embed fragment.
     Expects $selectedFy and $leaseEndSchedules. --}}
@if (count($leaseEndSchedules))
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">
                    {{ $selectedFy
                        ? trans('admin/purchase-orders/general.lease_end_title', ['fy' => $selectedFy])
                        : trans('admin/purchase-orders/general.lease_end_title_all') }}
                </h3>
                @can('create', \App\Models\Order::class)
                    <a href="{{ route('lease-decisions.create') }}" class="btn btn-default btn-xs pull-right">
                        {{ trans('admin/lease-decisions/general.create') }}
                    </a>
                @endcan
            </div>
            <div class="box-body">
                <p class="text-muted" style="margin-bottom:10px;">{{ trans('admin/purchase-orders/general.lease_end_help') }}</p>
                <style>
                    /* Keep contract / provider / end-date on one line each; let the
                       Models column be the flexible one that wraps and grows. The
                       Plan column has a reserved min-width so opening the inline
                       note editor doesn't reflow the whole table. */
                    .lease-end-table th:nth-child(1), .lease-end-table td:nth-child(1),
                    .lease-end-table th:nth-child(2), .lease-end-table td:nth-child(2),
                    .lease-end-table th:nth-child(3), .lease-end-table td:nth-child(3),
                    .lease-end-table th:nth-child(4), .lease-end-table td:nth-child(4) { white-space: nowrap; }
                    .lease-end-table td:nth-child(6) { white-space: normal; min-width: 280px; }
                    .lease-end-table th:nth-child(8), .lease-end-table td:nth-child(8) { min-width: 260px; }
                    .lease-end-table .rpt-note-input { width: 100%; box-sizing: border-box; }
                    /* The retained note is the plan, not a footnote: full
                       foreground colour, readable size, a quiet tinted block. */
                    .lease-end-retained-note {
                        display: block;
                        margin-top: 6px;
                        padding: 6px 10px;
                        font-size: 13px;
                        color: var(--color-fg, #262626);
                        background: light-dark(rgba(60, 141, 188, .08), rgba(60, 141, 188, .16));
                        border-radius: 6px;
                        max-width: 560px;
                    }
                </style>
                <div class="table-responsive">
                    <table class="table table-striped lease-end-table" style="margin-bottom:0;">
                        <thead>
                            <tr>
                                <th>{{ trans('admin/purchase-orders/general.lease_provider') }}</th>
                                <th>{{ trans('admin/lease-decisions/general.contract_reference') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.lease_end_ownership') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.lease_end_date') }}</th>
                                <th class="text-right">{{ trans('admin/purchase-orders/general.lease_end_devices') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.lease_end_models') }}</th>
                                <th class="text-right">{{ trans('admin/purchase-orders/general.lease_end_replacement') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.lease_end_plan') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($leaseEndSchedules as $schedule)
                                <tr>
                                    <td>{{ $schedule['provider'] }}</td>
                                    <td><strong>{{ $schedule['contract_id'] }}</strong></td>
                                    <td>
                                        @php $ownershipMix = $schedule['ownership_counts']; @endphp
                                        @if (empty($ownershipMix))
                                            <span class="text-muted">—</span>
                                        @elseif (count($ownershipMix) === 1)
                                            {{ array_key_first($ownershipMix) }}
                                        @else
                                            {{ collect($ownershipMix)->map(fn ($qty, $type) => $qty.'× '.$type)->implode(', ') }}
                                        @endif
                                    </td>
                                    <td>{{ $schedule['lease_end_date'] }}</td>
                                    <td class="text-right">{{ $schedule['count'] }}</td>
                                    <td>
                                        {{ collect($schedule['model_counts'])
                                            ->map(fn ($qty, $model) => $qty.'× '.$model)
                                            ->implode(', ') }}
                                    </td>
                                    <td class="text-right">${{ Helper::formatCurrencyOutput($schedule['cost']) }}</td>
                                    <td>
                                        @if ($schedule['is_lease_to_own'])
                                            <span class="label label-default">{{ trans('admin/purchase-orders/general.lease_end_retained') }}</span>
                                            {{-- The budget consequence is the decision — say it like one,
                                                 not like a footnote. A per-row plan note overrides the
                                                 generic retained text and is editable in place. --}}
                                            <span class="lease-end-retained-note rpt-note-cell" data-model="lease_plan_note" data-contract="{{ $schedule['contract_id'] }}">
                                                <span class="rpt-note-text">{{ $schedule['plan_note'] !== '' ? $schedule['plan_note'] : trans('admin/purchase-orders/general.lease_end_retained_help') }}</span>
                                                @can('create', \App\Models\Order::class)
                                                    <a href="#" class="rpt-note-edit" title="{{ trans('admin/purchase-orders/general.disposition_edit_note') }}"><i class="fa-solid fa-pencil" aria-hidden="true"></i></a>
                                                @endcan
                                            </span>
                                        @elseif ($schedule['decision'])
                                            <span class="label {{ $schedule['refresh_planned'] ? 'label-primary' : 'label-warning' }}">
                                                {{ trans('admin/purchase-orders/general.lease_end_decision_tag', [
                                                    'type' => trans('admin/lease-decisions/general.type_'.$schedule['decision']->decision_type),
                                                    'status' => trans('admin/purchase-orders/general.decision_status_'.$schedule['decision']->status),
                                                ]) }}
                                            </span>
                                            @unless ($schedule['refresh_planned'])
                                                <span class="text-muted" style="display:block; font-size:12px;">
                                                    {{ trans('admin/purchase-orders/general.lease_end_reassess') }}
                                                </span>
                                            @endunless
                                            <span class="rpt-note-cell" data-model="lease_decision" data-id="{{ $schedule['decision']->id }}" style="display:block; font-size:12px;">
                                                <span class="rpt-note-text text-muted">{{ $schedule['decision']->notes }}</span>
                                                @can('create', \App\Models\Order::class)
                                                    <a href="#" class="rpt-note-edit" title="{{ trans('admin/purchase-orders/general.disposition_edit_note') }}"><i class="fa-solid fa-pencil" aria-hidden="true"></i></a>
                                                @endcan
                                            </span>
                                        @else
                                            <span class="label label-success">{{ trans('admin/purchase-orders/general.lease_end_refresh_planned') }}</span>
                                            <span class="rpt-note-cell" data-model="lease_plan_note" data-contract="{{ $schedule['contract_id'] }}" style="display:block; font-size:12px;">
                                                <span class="rpt-note-text text-muted">{{ $schedule['plan_note'] }}</span>
                                                @can('create', \App\Models\Order::class)
                                                    <a href="#" class="rpt-note-edit" title="{{ trans('admin/purchase-orders/general.disposition_edit_note') }}"><i class="fa-solid fa-pencil" aria-hidden="true"></i></a>
                                                @endcan
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            @php
                                $leaseEndAll = collect($leaseEndSchedules);
                            @endphp
                            <tr>
                                <th colspan="4">{{ trans('admin/purchase-orders/general.lease_end_totals_preapproved') }}</th>
                                <th class="text-right">{{ $leaseEndAll->sum('count') }}</th>
                                <th></th>
                                <th class="text-right">${{ Helper::formatCurrencyOutput($leaseEndAll->sum('cost')) }}</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endif
