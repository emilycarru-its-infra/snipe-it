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

{{-- FY selector --}}
<div style="display:flex; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:15px;">
    <form method="GET" action="{{ route('deployments.forecast') }}" style="display:flex; align-items:center; gap:8px; margin:0;">
        <label style="margin:0;">{{ trans('admin/deployments/general.filter_fiscal_year') }}</label>
        <select name="fiscal_year" class="form-control" style="width:auto;" onchange="this.form.submit()">
            @foreach ($fiscalYears as $y)
                <option value="{{ $y }}" {{ (string) $fy === (string) $y ? 'selected' : '' }}>{{ $y }}</option>
            @endforeach
        </select>
    </form>
    <a href="{{ route('reports.deployments', ['fiscal_year' => $fy]) }}" class="btn btn-default">{{ trans('admin/deployments/general.dashboard_title') }}</a>
    <a href="{{ route('deployments.storage') }}" class="btn btn-default"><i class="fas fa-boxes"></i> {{ trans('admin/deployments/general.storage_title') }}</a>
    <a href="{{ route('deployments.blackouts.index') }}" class="btn btn-default"><i class="fas fa-user-clock"></i> {{ trans('admin/deployments/general.blackouts_button') }}</a>
    @can('deployments.edit')
        @include('reports.deployments._new-wave-popover', ['popoverId' => 'fc-new-wave', 'fy' => $fy, 'types' => $types])
    @endcan
</div>

@if (! $fy)
    <div class="alert alert-info">{{ trans('admin/deployments/general.forecast_choose_fy') }}</div>
@else

<style>
    .fc-group-head td {
        font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: .05em;
        background: color-mix(in srgb, var(--main-theme-color, #3c8dbc) 8%, var(--box-bg, #fff));
    }
    .fc-scroll { max-height: 62vh; overflow: auto; }
    .fc-scroll thead th { position: sticky; top: 0; z-index: 2; background: var(--box-bg, #fff); box-shadow: 0 1px 0 var(--box-border-color, #f4f4f4); }
</style>

@if ($waves->isNotEmpty())
@include('reports.deployments._timeline')
@endif

<form method="POST" action="{{ route('deployments.forecast.add') }}">
    {{ csrf_field() }}
    <input type="hidden" name="fiscal_year" value="{{ $fy }}">

    <div class="row" style="display:flex; flex-wrap:wrap;">
        @if ($waves->isNotEmpty())
        <div class="col-md-6" style="display:flex;">
            <div style="flex:1;">
{{-- The FY's waves at a glance. --}}
<div class="box box-default">
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
                    <th>{{ trans('admin/deployments/general.arrival_window') }}</th>
                    <th>{{ trans('admin/deployments/general.deploy_window') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($waves as $wave)
                <tr>
                    <td><a href="{{ route('deployment-waves.show', $wave) }}"><span class="label" style="background-color: {{ $wave->displayColor() }}; color:#fff;">{{ $wave->name }}</span></a></td>
                    <td>{{ $wave->typeLabel() }}</td>
                    <td>{{ ucfirst($wave->wave_state) }}</td>
                    <td class="text-right">{{ $wave->items_count }}</td>
                    <td>
                        @if ($wave->arrival_window_start || $wave->arrival_window_end)
                            {{ optional($wave->arrival_window_start)->toDateString() ?: '?' }} – {{ optional($wave->arrival_window_end)->toDateString() ?: '?' }}
                        @else — @endif
                    </td>
                    <td>
                        @if ($wave->target_start_date || $wave->target_end_date)
                            {{ optional($wave->target_start_date)->toDateString() ?: '?' }} – {{ optional($wave->target_end_date)->toDateString() ?: '?' }}
                        @else — @endif
                    </td>
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
        @endif
        {{-- Just the add-to-wave control: creating a wave lives behind the
             New Wave button, so the creation fields here were a second copy
             of the same form. Pick the wave, add the selection. --}}
        <div class="{{ $waves->isNotEmpty() ? 'col-md-6' : 'col-md-12' }}" style="display:flex;">
            <div class="box box-default" style="flex:1;">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/deployments/general.add_from_forecast') }}</h3>
                </div>
                <div class="box-body" style="display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end;">
                    <div>
                        <label style="display:block; margin-bottom:3px;">{{ trans('admin/deployments/general.target_wave') }}</label>
                        <select name="wave_id" class="form-control" style="width:auto; min-width:220px;">
                            <option value="">—</option>
                            @foreach ($waves as $w)
                                <option value="{{ $w->id }}">{{ $w->name }} ({{ $w->items_count }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> {{ trans('admin/deployments/general.add_from_forecast') }}
                            (<span id="fc-sel-count">0</span>)
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
                @php($reasonLabel = ['eol' => trans('admin/deployments/general.reason_eol'), 'lease' => trans('admin/deployments/general.reason_lease'), 'both' => trans('admin/deployments/general.reason_both')])
                @forelse ($candidates as $idx => $asset)
                    <tr data-idx="{{ $idx }}"
                        data-location="{{ $asset->location?->name ?: '—' }}"
                        data-model="{{ $asset->model?->name ?: '—' }}"
                        data-reason="{{ $reasonLabel[$asset->refresh_reason] ?? $asset->refresh_reason }}"
                        data-decision="{{ $asset->lease_decision_label ?: '—' }}">
                        <td><input type="checkbox" class="fc-check" name="asset_ids[]" value="{{ $asset->id }}"></td>
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
    </div>
</form>

<script nonce="{{ csrf_token() }}">
(function () {
    var tbody = document.getElementById('fc-rows');
    if (!tbody) { return; }
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-idx]'));
    var groupBy = '';
    var selCount = document.getElementById('fc-sel-count');

    function refreshCount() {
        selCount.textContent = rows.filter(function (r) { return r.querySelector('.fc-check').checked; }).length;
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
