@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.forecast_title') }} @parent
@stop



@section('content')

<p class="text-muted">{{ trans('admin/deployments/general.forecast_help') }}</p>

@unless ($leaseColumnPresent)
    <div class="alert alert-warning">
        <i class="fas fa-info-circle"></i> {{ trans('admin/deployments/general.forecast_lease_missing') }}
    </div>
@endunless

@php $sanitiseListId = fn ($key) => 'fcl-'.preg_replace('/[^a-z0-9]/i', '-', $key); @endphp

{{-- FY selector --}}
<div style="display:flex; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:15px;">
    <form method="GET" action="{{ route('deployments.planning') }}" style="display:flex; align-items:center; gap:8px; margin:0;">
        <label style="margin:0;">{{ trans('admin/deployments/general.filter_fiscal_year') }}</label>
        <select name="fiscal_year" class="form-control" style="width:auto;" onchange="this.form.submit()">
            @foreach ($fiscalYears as $y)
                <option value="{{ $y }}" {{ (string) $fy === (string) $y ? 'selected' : '' }}>{{ $y }}</option>
            @endforeach
        </select>
    </form>
    <a href="{{ route('reports.deployments', ['fiscal_year' => $fy]) }}" class="btn btn-default">{{ trans('admin/deployments/general.dashboard_title') }}</a>
    <a href="{{ route('deployment-waves.index') }}" class="btn btn-default"><i class="fas fa-water"></i> {{ trans('admin/deployments/general.waves_title') }}</a>
    <a href="{{ route('deployments.storage') }}" class="btn btn-default"><i class="fas fa-boxes"></i> {{ trans('admin/deployments/general.storage_title') }}</a>
    {{-- Early renewal / criteria, behind a button — the entry form is a
         once-in-a-while tool and was costing a whole box of the page. --}}
    <span class="nw-pop-wrap" style="position:relative; display:inline-block;">
        <button type="button" class="btn {{ ($earlyRenewalMode ?? false) ? 'btn-primary' : 'btn-default' }} nw-pop-toggle" data-pop="fc-criteria-pop">
            <i class="fas fa-filter" aria-hidden="true"></i> {{ trans('admin/purchase-orders/general.forecast_criteria_title') }}
            @if ($earlyRenewalMode ?? false)<span class="badge">{{ count($activeCriteria) }}</span>@endif
        </button>
        <div class="nw-pop" id="fc-criteria-pop" style="width:520px;">
            <p class="text-muted" style="font-size:12px;">{{ trans('admin/purchase-orders/general.forecast_criteria_help') }}</p>
            <form method="get" id="forecast-criteria-form">
                <input type="hidden" name="fiscal_year" value="{{ $fy }}">
                <div id="forecast-criteria-rows">
                    @php $criteriaRows = ! empty($activeCriteria) ? $activeCriteria : [['field' => '', 'value' => '']]; @endphp
                    @foreach ($criteriaRows as $i => $c)
                        <div class="forecast-criteria-row" style="display:flex; gap:6px; margin-bottom:6px;">
                            <select name="criteria[{{ $i }}][field]" class="form-control input-sm fc-field" style="flex:1;">
                                <option value="">{{ trans('admin/purchase-orders/general.forecast_criteria_field') }}</option>
                                @foreach ($filterFields as $key => $label)
                                    <option value="{{ $key }}" {{ ($c['field'] ?? '') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            <input type="text" name="criteria[{{ $i }}][value]" class="form-control input-sm fc-value" style="flex:1;"
                                   value="{{ $c['value'] ?? '' }}"
                                   @if (! empty($c['field']) && ! empty($filterValues[$c['field']])) list="{{ $sanitiseListId($c['field']) }}" @endif
                                   placeholder="{{ trans('admin/purchase-orders/general.forecast_criteria_value') }}">
                            <button type="button" class="btn btn-default btn-sm fc-remove" title="{{ trans('button.delete') }}">&times;</button>
                        </div>
                    @endforeach
                </div>
                <div style="display:flex; align-items:center; gap:6px; margin-top:8px;">
                    <button type="button" id="fc-add" class="btn btn-default btn-sm">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i> {{ trans('admin/purchase-orders/general.forecast_criteria_add') }}
                    </button>
                    <span style="flex:1;"></span>
                    @if ($earlyRenewalMode ?? false)
                        <a href="{{ route('deployments.planning', ['fiscal_year' => $fy]) }}" class="btn btn-link btn-sm">{{ trans('admin/purchase-orders/general.forecast_criteria_clear') }}</a>
                    @endif
                    <button type="submit" class="btn btn-primary btn-sm">{{ trans('admin/purchase-orders/general.forecast_criteria_apply') }}</button>
                </div>
            </form>
        </div>
    </span>
    @if ($earlyRenewalMode ?? false)
        <span class="label label-primary" style="align-self:center;">{{ trans('admin/purchase-orders/general.forecast_early_renewal_badge') }}</span>
    @endif
    <a href="{{ route('deployments.planning', array_filter(['fiscal_year' => $fy, 'format' => 'csv', 'criteria' => $activeCriteria])) }}" class="btn btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
    @can('deployments.edit')
        @include('reports.deployments._new-wave-popover', ['popoverId' => 'fc-new-wave', 'fy' => $fy, 'types' => $types])
    @endcan
</div>


@include('reports.deployments._popover-assets')

@foreach ($filterValues as $key => $vals)
    @if (! empty($vals))
        <datalist id="{{ $sanitiseListId($key) }}">
            @foreach ($vals as $v)<option value="{{ $v }}">@endforeach
        </datalist>
    @endif
@endforeach

@if (! $fy)
    <div class="alert alert-info">{{ trans('admin/deployments/general.forecast_choose_fy') }}</div>
@else

<style>
    .fc-group-head td {
        font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: .05em;
        background: color-mix(in srgb, var(--main-theme-color, #3c8dbc) 8%, var(--box-bg, #fff));
    }
    .fc-scroll { max-height: 62vh; overflow: auto; }
    {{-- Half-width timeline: the box fills the column and the label rail
         narrows so the bars keep most of the width. --}}
    .fc-timeline-half > .box { flex: 1; margin-bottom: 20px; }
    .fc-timeline-half .gantt { --gantt-rail: 150px; }
    {{-- Same collapse-vs-sticky Chrome bug the layout's .sticky-table
         guards against: without separate, rows bleed through the header. --}}
    .fc-scroll table { border-collapse: separate; border-spacing: 0; }
    .fc-bulkbar { display: none; padding: 8px 10px; border-bottom: 1px solid var(--box-border-color, #f4f4f4); }
    .fc-bulkbar.active { display: block; }
    .fc-bulkbar .form-control { display: inline-block; width: auto; vertical-align: middle; }
    .fc-scroll thead th { position: sticky; top: 0; z-index: 2; background: var(--box-bg, #fff); box-shadow: 0 1px 0 var(--box-border-color, #f4f4f4); }
</style>

@if ($waves->isNotEmpty())
{{-- Waves and their Gantt side by side, 50/50 — the two views of the
     same plan read together, not stacked a screen apart. --}}
<div class="row" style="display:flex; flex-wrap:wrap; align-items:stretch;">
    <div class="col-md-6" style="display:flex;">
        <div style="flex:1; display:flex; flex-direction:column;">
{{-- The FY's waves at a glance. --}}
<div class="box box-default" style="flex:1;">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.waves_title') }}</h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('admin/deployments/general.name') }}</th>
                    <th>{{ trans('admin/deployments/general.deployment_type') }}</th>
                    <th>{{ trans('admin/deployments/general.wave_state') }}</th>
                    <th class="text-right">{{ trans('admin/deployments/general.device') }}s</th>
                    <th>{{ trans('admin/deployments/general.waves_col_arrival') }}</th>
                    <th>{{ trans('admin/deployments/general.waves_col_deploy') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($waves as $wave)
                <tr>
                    <td><a class="js-lightbox" href="{{ route('deployment-waves.show', $wave) }}"><span class="label" style="background-color: {{ $wave->displayColor() }}; color:#fff;">{{ $wave->name }}</span></a></td>
                    <td>{{ $wave->typeLabel() }}</td>
                    <td>{{ ucfirst($wave->wave_state) }}</td>
                    <td class="text-right">{{ $wave->items_count }}</td>
                    <td>{{ optional($wave->arrival_window_start)->toDateString() ?: '—' }}</td>
                    <td>{{ optional($wave->target_start_date)->toDateString() ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted">{{ trans('admin/deployments/general.no_waves') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
        </div>
    </div>
    <div class="col-md-6" style="display:flex;">
        <div style="flex:1; display:flex; flex-direction:column;" class="fc-timeline-half">
            @include('reports.deployments._timeline')
        </div>
    </div>
</div>
@endif

<form method="POST" action="{{ route('deployments.planning.add') }}">
    {{ csrf_field() }}
    <input type="hidden" name="fiscal_year" value="{{ $fy }}">

    {{-- Candidate assets --}}
    <div class="box box-default">
        <div class="box-header with-border">
            <h3 class="box-title">{{ trans('admin/deployments/general.forecast_summary', ['count' => $candidates->count(), 'fy' => $fy]) }}</h3>
            <span style="margin-left:16px; vertical-align:middle; white-space:nowrap;">
                <span class="text-muted" style="font-size:12px; margin-right:4px;">{{ trans('admin/deployments/general.flow_group_label') }}</span>
                <span class="btn-group" id="fc-group-btns" style="vertical-align:middle;">
                    <button type="button" class="btn btn-xs btn-default active" data-group="">{{ trans('admin/deployments/general.flow_group_none') }}</button>
                    <button type="button" class="btn btn-xs btn-default" data-group="location">{{ trans('admin/deployments/general.flow_group_location') }}</button>
                    <button type="button" class="btn btn-xs btn-default" data-group="model">{{ trans('admin/deployments/general.flow_group_model') }}</button>
                    <button type="button" class="btn btn-xs btn-default" data-group="reason">{{ trans('admin/deployments/general.refresh_reason') }}</button>
                    <button type="button" class="btn btn-xs btn-default" data-group="decision">{{ trans('admin/deployments/general.forecast_col_decision') }}</button>
                </span>
            </span>
        </div>

        {{-- The selection bar: appears when devices are checked, and IS
             the answer to "what do I do with this selection" — send it to
             an existing wave, or name a new one right here and it is
             created with the devices already on it. --}}
        <div class="fc-bulkbar" id="fc-bulkbar">
            <strong id="fc-sel-label"></strong>
            <span style="margin-left:12px;">
                {{ trans('admin/deployments/general.forecast_send_to_wave') }}:
                <select name="wave_id" class="form-control input-sm">
                    <option value="">—</option>
                    @foreach ($waves as $w)
                        <option value="{{ $w->id }}">{{ $w->name }} ({{ $w->items_count }})</option>
                    @endforeach
                </select>
                <span class="text-muted" style="margin:0 6px;">{{ trans('admin/deployments/general.forecast_or_new_wave') }}</span>
                <input type="text" name="new_wave_name" class="form-control input-sm" style="width:220px;" placeholder="{{ trans('admin/deployments/general.new_wave_name') }}">
                <select name="deployment_type_id" class="form-control input-sm">
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus"></i> {{ trans('admin/deployments/general.forecast_send_submit') }} (<span id="fc-sel-count">0</span>)
                </button>
                @if ($nextFy)
                    <button type="submit" class="btn btn-sm btn-default" formaction="{{ route('deployments.planning.defer') }}"
                            title="{{ trans('admin/deployments/general.defer_help') }}"
                            onclick="return confirm({!! json_encode(trans('admin/deployments/general.defer_confirm', ['fy' => $nextFy])) !!});">
                        <i class="fas fa-forward"></i> {{ trans('admin/deployments/general.defer_submit', ['fy' => $nextFy]) }}
                    </button>
                @endif
                <span class="text-muted" style="margin:0 4px 0 10px;">{{ trans('admin/deployments/general.buyout_or') }}</span>
                <input type="number" step="0.01" min="0" name="buyout_estimate" class="form-control input-sm" style="width:130px;"
                       placeholder="{{ trans('admin/deployments/general.buyout_estimate_placeholder') }}"
                       title="{{ trans('admin/deployments/general.buyout_estimate_help') }}">
                <button type="submit" class="btn btn-sm btn-default" formaction="{{ route('deployments.planning.buyout') }}"
                        title="{{ trans('admin/deployments/general.buyout_decide_help') }}"
                        onclick="var est = this.form.querySelector('[name=buyout_estimate]'); if (! est.value) { est.focus(); est.reportValidity ? (est.required = true, est.reportValidity(), est.required = false) : alert({!! json_encode(trans('admin/deployments/general.buyout_estimate_required')) !!}); return false; } return confirm({!! json_encode(trans('admin/deployments/general.buyout_decide_confirm')) !!});">
                    <i class="fas fa-hand-holding-usd"></i> {{ trans('admin/deployments/general.buyout_decide_submit') }}
                </button>
            </span>
        </div>
        <div class="box-body no-padding fc-scroll">
            <table class="table table-hover table-striped table-condensed" style="margin-bottom:0;">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" id="fc-select-all"></th>
                        <th>{{ trans('admin/deployments/general.device') }}</th>
                        <th>{{ trans('admin/deployments/general.model') }}</th>
                        <th>{{ trans('admin/deployments/general.refresh_reason') }}</th>
                        <th>{{ trans('admin/deployments/general.source_date') }}</th>
                        <th>{{ trans('general.status') }}</th>
                        <th>{{ trans('admin/deployments/general.location') }}</th>
                        <th>{{ trans('admin/deployments/general.forecast_col_decision') }}</th>
                    </tr>
                </thead>
                <tbody id="fc-rows">
                @php($reasonLabel = ['eol' => trans('admin/deployments/general.reason_eol'), 'lease' => trans('admin/deployments/general.reason_lease'), 'both' => trans('admin/deployments/general.reason_both'), 'funded' => trans('admin/deployments/general.reason_funded'), 'criteria' => trans('admin/deployments/general.reason_criteria'), 'deferred' => trans('admin/deployments/general.reason_deferred')])
                @forelse ($candidates as $idx => $asset)
                    <tr data-idx="{{ $idx }}"
                        data-location="{{ $asset->location?->name ?: '—' }}"
                        data-model="{{ $asset->model?->name ?: '—' }}"
                        data-reason="{{ $reasonLabel[$asset->refresh_reason] ?? $asset->refresh_reason }}"
                        data-decision="{{ $asset->lease_decision_label ?: '—' }}">
                        <td>
                            @if (in_array($asset->id, $plannedAssetIds, true))
                                <span class="label label-info" title="{{ trans('admin/purchase-orders/general.forecast_planned_already') }}">{{ trans('admin/purchase-orders/general.forecast_planned_already') }}</span>
                            @else
                                <input type="checkbox" class="fc-check" name="asset_ids[]" value="{{ $asset->id }}">
                            @endif
                        </td>
                        <td><a href="{{ route('hardware.show', $asset) }}" class="js-lightbox">{{ $asset->name ?: $asset->asset_tag ?: ('#'.$asset->id) }}</a></td>
                        <td>{{ $asset->model?->name ?: '—' }}</td>
                        <td><span class="label label-default">{{ $reasonLabel[$asset->refresh_reason] ?? $asset->refresh_reason }}</span></td>
                        <td>{{ $asset->source_date ?: '—' }}</td>
                        <td>{{ $asset->status?->name ?: '—' }}</td>
                        <td>{{ $asset->location?->name ?: '—' }}</td>
                        <td>
                            @if ($asset->lease_decision_label)
                                <span class="label label-warning" @if ($asset->lease_decision_note) title="{{ $asset->lease_decision_note }}" @endif>
                                    {{ $asset->lease_decision_label }}
                                </span>
                            @else — @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted">{{ trans('admin/deployments/general.forecast_no_candidates') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @can('create', \App\Models\Order::class)
            {{-- The same selection can also become a planned order — the
                 forecast's dollars on the procurement dashboard. Same
                 checkboxes, different button. --}}
            <div class="box-footer form-inline" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <label for="fc-order-number" style="margin:0;">{{ trans('admin/purchase-orders/general.forecast_order_number') }}</label>
                <input type="text" name="order_number" id="fc-order-number" class="form-control input-sm" maxlength="191">
                <button type="submit" class="btn btn-default btn-sm"
                        formaction="{{ route('reports.procurement.forecast.plan') }}">
                    {{ trans('admin/purchase-orders/general.forecast_create_planned') }}
                    (<span class="fc-sel-count-mirror">0</span>)
                </button>
            </div>
        @endcan
    </div>
</form>

{{-- Incoming orders that no wave has claimed. These are procurement
     facts headed for deployment — planning's business until a wave item
     claims them (which the stage automation does the moment the joins
     line up). Always expanded: an order you can't see is an order you
     forget to plan. --}}
@if ($incomingOrders->isNotEmpty())
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.incoming_orders_title', ['count' => $incomingOrders->flatten(1)->count()]) }}</h3>
        <span class="text-muted" style="font-size:12px; margin-left:10px;">{{ trans('admin/deployments/general.incoming_orders_hint') }}</span>
    </div>
    <div class="box-body no-padding">
        <table class="table table-striped table-condensed" style="margin-bottom:0;">
            <thead>
                <tr>
                    <th>{{ trans('admin/deployments/general.device') }}</th>
                    <th>{{ trans('admin/deployments/general.model') }}</th>
                    <th>{{ trans('admin/deployments/general.stage') }}</th>
                    <th>{{ trans('admin/orders/general.order') }}</th>
                    <th>{{ trans('general.status') }}</th>
                    <th>{{ trans('admin/deployments/general.location') }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($incomingOrders as $orderNumber => $lines)
                <tr class="fc-group-head"><td colspan="6">{{ $orderNumber }} · {{ count($lines) }}</td></tr>
                @foreach ($lines as $line)
                    <tr>
                        <td>
                            @if ($line['device_url'])
                                <a href="{{ $line['device_url'] }}" class="js-lightbox">{{ $line['device'] }}</a>
                            @else
                                {{ $line['device'] }}
                            @endif
                        </td>
                        <td>{{ $line['model'] }}</td>
                        <td><span class="label" style="background-color: {{ $line['stage_color'] }}; color:#fff;">{{ $line['stage_name'] }}</span></td>
                        <td><a href="{{ $line['wave_url'] }}" class="js-lightbox">{{ $line['wave'] }}</a> <span class="text-muted" style="font-size:11.5px;">{{ $line['context'] }}</span></td>
                        <td>{{ $line['status'] }}</td>
                        <td>{{ $line['location'] }}</td>
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<script nonce="{{ csrf_token() }}">
(function () {
    var rows = document.getElementById('forecast-criteria-rows');
    if (!rows) { return; }

    function nextIndex() {
        var max = -1;
        rows.querySelectorAll('.fc-field').forEach(function (sel) {
            var m = sel.name.match(/criteria\[(\d+)\]/);
            if (m) { max = Math.max(max, parseInt(m[1], 10)); }
        });
        return max + 1;
    }

    function listIdFor(key) { return 'fcl-' + key.replace(/[^a-z0-9]/gi, '-'); }

    function wireRow(row) {
        var field = row.querySelector('.fc-field');
        var value = row.querySelector('.fc-value');
        var remove = row.querySelector('.fc-remove');
        if (field) {
            field.addEventListener('change', function () {
                var id = listIdFor(field.value);
                if (field.value && document.getElementById(id)) {
                    value.setAttribute('list', id);
                } else {
                    value.removeAttribute('list');
                }
                value.value = '';
            });
        }
        if (remove) {
            remove.addEventListener('click', function () {
                if (rows.querySelectorAll('.forecast-criteria-row').length > 1) {
                    row.remove();
                } else {
                    field.value = '';
                    value.value = '';
                    value.removeAttribute('list');
                }
            });
        }
    }

    rows.querySelectorAll('.forecast-criteria-row').forEach(wireRow);

    var add = document.getElementById('fc-add');
    if (add) {
        add.addEventListener('click', function () {
            var clone = rows.querySelector('.forecast-criteria-row').cloneNode(true);
            var idx = nextIndex();
            var f = clone.querySelector('.fc-field');
            var v = clone.querySelector('.fc-value');
            f.name = 'criteria[' + idx + '][field]';
            f.value = '';
            v.name = 'criteria[' + idx + '][value]';
            v.value = '';
            v.removeAttribute('list');
            rows.appendChild(clone);
            wireRow(clone);
        });
    }
})();
(function () {
    var tbody = document.getElementById('fc-rows');
    if (!tbody) { return; }
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-idx]'));
    var groupBy = '';
    var selCount = document.getElementById('fc-sel-count');

    var bulkbar = document.getElementById('fc-bulkbar');
    var selLabel = document.getElementById('fc-sel-label');
    var selLabelTemplate = @json(trans('admin/deployments/general.flow_selected', ['count' => '__N__']));

    function refreshCount() {
        var n = rows.filter(function (r) {
            var check = r.querySelector('.fc-check');
            return check && check.checked;
        }).length;
        selCount.textContent = n;
        document.querySelectorAll('.fc-sel-count-mirror').forEach(function (el) { el.textContent = n; });
        if (bulkbar) {
            bulkbar.classList.toggle('active', n > 0);
            selLabel.textContent = selLabelTemplate.replace('__N__', n);
        }
    }

    function rebuild() {
        Array.prototype.forEach.call(tbody.querySelectorAll('tr.fc-group-head'), function (h) { h.remove(); });

        if (!groupBy) {
            rows.slice().sort(function (a, b) {
                return (+a.getAttribute('data-idx')) - (+b.getAttribute('data-idx'));
            }).forEach(function (r) { tbody.appendChild(r); });
            return;
        }

        var groups = {};
        var order = [];
        rows.forEach(function (r) {
            var key = r.getAttribute('data-' + groupBy) || '—';
            if (!groups[key]) { groups[key] = []; order.push(key); }
            groups[key].push(r);
        });
        order.sort(function (a, b) { return groups[b].length - groups[a].length; });
        order.forEach(function (key) {
            var head = document.createElement('tr');
            head.className = 'fc-group-head';
            var td = document.createElement('td');
            td.colSpan = 8;
            var check = document.createElement('input');
            check.type = 'checkbox';
            check.style.marginRight = '8px';
            td.appendChild(check);
            td.appendChild(document.createTextNode(key + ' · ' + groups[key].length));
            head.appendChild(td);
            tbody.appendChild(head);
            groups[key].forEach(function (r) { tbody.appendChild(r); });
            check.addEventListener('change', function () {
                groups[key].forEach(function (r) { r.querySelector('.fc-check').checked = check.checked; });
                refreshCount();
            });
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('#fc-group-btns button'), function (btn) {
        btn.addEventListener('click', function () {
            Array.prototype.forEach.call(document.querySelectorAll('#fc-group-btns button'), function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            groupBy = btn.getAttribute('data-group');
            rebuild();
        });
    });

    document.getElementById('fc-select-all').addEventListener('change', function () {
        var check = this.checked;
        rows.forEach(function (r) { r.querySelector('.fc-check').checked = check; });
        refreshCount();
    });

    tbody.addEventListener('change', function (e) {
        if (e.target.classList.contains('fc-check')) { refreshCount(); }
    });

    refreshCount();
})();
</script>
@endif

@stop
