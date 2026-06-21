@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ $reportTitle }}
    @parent
@stop

{{-- Page-header actions --}}
@section('header_right')
    @if (! empty($fyFilterable))
        <form method="get" style="display:inline-block; margin-right:4px;">
            @foreach (($activeCriteria ?? []) as $i => $c)
                <input type="hidden" name="criteria[{{ $i }}][field]" value="{{ $c['field'] }}">
                <input type="hidden" name="criteria[{{ $i }}][value]" value="{{ $c['value'] }}">
            @endforeach
            <select name="fiscal_year" class="form-control input-sm" style="display:inline-block; width:auto;" onchange="this.form.submit()">
                <option value="all" {{ ($selectedFy ?? null) === null ? 'selected' : '' }}>{{ trans('admin/purchase-orders/general.all_fiscal_years') }}</option>
                @foreach (($allFiscalYears ?? collect()) as $fy)
                    <option value="{{ $fy }}" {{ ($selectedFy ?? null) === $fy ? 'selected' : '' }}>{{ $fy }}</option>
                @endforeach
            </select>
        </form>
    @endif
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
    <a href="{{ route('reports.procurement') }}" class="btn btn-sm btn-default">
        {{ trans('admin/purchase-orders/general.reports') }}
    </a>
@stop

{{-- Page content --}}
@section('content')
@php
    $sanitiseListId = fn ($key) => 'fcl-'.preg_replace('/[^a-z0-9]/i', '-', $key);
@endphp
<div class="row">
    <div class="col-md-12">
        <div class="box {{ ($earlyRenewalMode ?? false) ? 'box-primary' : 'box-default' }}">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.forecast_criteria_title') }}</h3>
            </div>
            <div class="box-body">
                <p class="text-muted">{{ trans('admin/purchase-orders/general.forecast_criteria_help') }}</p>
                <form method="get" id="forecast-criteria-form">
                    <input type="hidden" name="fiscal_year" value="{{ $selectedFy ?? 'all' }}">
                    <div id="forecast-criteria-rows">
                        @php $criteriaRows = ! empty($activeCriteria) ? $activeCriteria : [['field' => '', 'value' => '']]; @endphp
                        @foreach ($criteriaRows as $i => $c)
                            <div class="forecast-criteria-row form-inline" style="margin-bottom:6px;">
                                <select name="criteria[{{ $i }}][field]" class="form-control fc-field" style="min-width:220px;">
                                    <option value="">{{ trans('admin/purchase-orders/general.forecast_criteria_field') }}</option>
                                    @foreach ($filterFields as $key => $label)
                                        <option value="{{ $key }}" {{ ($c['field'] ?? '') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="criteria[{{ $i }}][value]" class="form-control fc-value" style="min-width:220px;"
                                       value="{{ $c['value'] ?? '' }}"
                                       @if (! empty($c['field']) && ! empty($filterValues[$c['field']])) list="{{ $sanitiseListId($c['field']) }}" @endif
                                       placeholder="{{ trans('admin/purchase-orders/general.forecast_criteria_value') }}">
                                <button type="button" class="btn btn-default fc-remove" title="{{ trans('button.delete') }}">&times;</button>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" id="fc-add" class="btn btn-default btn-sm" style="margin-top:6px;">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i> {{ trans('admin/purchase-orders/general.forecast_criteria_add') }}
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:6px;">{{ trans('admin/purchase-orders/general.forecast_criteria_apply') }}</button>
                    @if ($earlyRenewalMode ?? false)
                        <a href="{{ route('reports.procurement.forecast', ['fiscal_year' => $selectedFy ?? 'all']) }}" class="btn btn-link btn-sm" style="margin-top:6px;">{{ trans('admin/purchase-orders/general.forecast_criteria_clear') }}</a>
                        <span class="label label-primary" style="margin-left:8px;">{{ trans('admin/purchase-orders/general.forecast_early_renewal_badge') }}</span>
                    @endif
                </form>
                @foreach ($filterValues as $key => $vals)
                    @if (! empty($vals))
                        <datalist id="{{ $sanitiseListId($key) }}">
                            @foreach ($vals as $v)<option value="{{ $v }}">@endforeach
                        </datalist>
                    @endif
                @endforeach
            </div>
        </div>

        <form method="POST" action="{{ route('reports.procurement.forecast.plan') }}">
            {{ csrf_field() }}
            <div class="box box-default">
                <div class="box-body">
                    @if ($canCreate)
                        <p>{{ trans('admin/purchase-orders/general.forecast_intro') }}</p>
                        <div class="form-inline" style="margin-bottom: 15px;">
                            <div class="form-group {{ $errors->has('order_number') ? 'has-error' : '' }}">
                                <label for="order_number">{{ trans('admin/purchase-orders/general.forecast_order_number') }}</label>
                                <input type="text" name="order_number" id="order_number" class="form-control"
                                       value="{{ old('order_number') }}" maxlength="191" required>
                            </div>
                            <div class="form-group {{ $errors->has('fiscal_year') ? 'has-error' : '' }}">
                                <label for="fiscal_year">{{ trans('admin/purchase-orders/general.fiscal_year') }}</label>
                                <input type="text" name="fiscal_year" id="fiscal_year" class="form-control"
                                       value="{{ old('fiscal_year', $selectedFy) }}" maxlength="191">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                {{ trans('admin/purchase-orders/general.forecast_create_planned') }}
                            </button>
                        </div>
                    @endif
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    @if ($canCreate)
                                        <th>{{ trans('admin/purchase-orders/general.forecast_select') }}</th>
                                    @endif
                                    @foreach ($columns as $col)
                                        <th>{{ $col }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($rows as $row)
                                <tr @if (! empty($row['class'])) class="{{ $row['class'] }}" @endif>
                                    @if ($canCreate)
                                        <td>
                                            @if (! empty($row['planned']))
                                                <span class="label label-info">{{ trans('admin/purchase-orders/general.forecast_planned_already') }}</span>
                                            @else
                                                <input type="checkbox" name="assets[]" value="{{ $row['asset_id'] }}">
                                            @endif
                                        </td>
                                    @endif
                                    @foreach ($row['cells'] as $cell)
                                        <td>{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($columns) + ($canCreate ? 1 : 0) }}">{{ trans('general.no_results') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                            @if (! empty($footer))
                                <tfoot>
                                    <tr>
                                        @if ($canCreate)<th></th>@endif
                                        @foreach ($footer as $cell)
                                            <th>{{ $cell }}</th>
                                        @endforeach
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
@stop

@section('moar_scripts')
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
</script>
@stop
