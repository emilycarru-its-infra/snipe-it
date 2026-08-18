@extends('layouts/default')

{{-- The canonical faculty-program ledger: one row per USER, one column per
     agreement type, lifecycle pill linking to the agreement record. The
     deployments board embeds the same pivot; per-agreement workflow actions
     (regenerate, send, payroll, cancel) live on the agreement page behind
     the pill link. --}}

@php
    use App\Models\UserAgreement;
@endphp

@section('title')
    {{ $reportTitle }} @parent
@stop

@section('header_right')
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <form method="GET" action="{{ route('reports.procurement.user-agreement-ledger') }}" class="form-inline" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                    <div class="form-group">
                        <label for="filter-type" style="display:block;">{{ trans('admin/user-agreements/general.filter_type') }}</label>
                        <select id="filter-type" name="agreement_type" class="form-control">
                            <option value="">{{ trans('admin/user-agreements/general.filter_all_types') }}</option>
                            @foreach (UserAgreement::AGREEMENT_TYPES as $t)
                                <option value="{{ $t }}" @selected($typeFilter === $t)>
                                    {{ trans('admin/purchase-orders/general.user_agreement_type_value_'.$t) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="filter-stage" style="display:block;">{{ trans('admin/user-agreements/general.filter_stage') }}</label>
                        <select id="filter-stage" name="stage" class="form-control">
                            <option value="">{{ trans('admin/user-agreements/general.filter_all_stages') }}</option>
                            @foreach (UserAgreement::LIFECYCLE_STAGES as $s)
                                <option value="{{ $s }}" @selected($stageFilter === $s)>
                                    {{ trans('admin/purchase-orders/general.user_agreement_stage_value_'.$s) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="filter-q" style="display:block;">{{ trans('general.search') }}</label>
                        <input type="text" id="filter-q" name="q" class="form-control" value="{{ $searchFilter ?? '' }}"
                               placeholder="{{ trans('admin/user-agreements/general.search_placeholder') }}">
                    </div>
                    <div class="form-group">
                        <label for="filter-fy" style="display:block;">{{ trans('admin/purchase-orders/general.fiscal_year') }}</label>
                        <select id="filter-fy" name="fiscal_year" class="form-control">
                            <option value="all" {{ ($selectedFy ?? null) === null ? 'selected' : '' }}>{{ trans('admin/purchase-orders/general.all_fiscal_years') }}</option>
                            @foreach (($allFiscalYears ?? collect()) as $fyOption)
                                <option value="{{ $fyOption }}" {{ ($selectedFy ?? null) === $fyOption ? 'selected' : '' }}>{{ $fyOption }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">{{ trans('admin/user-agreements/general.apply_filters') }}</button>
                        <a href="{{ route('reports.procurement.user-agreement-ledger') }}" class="btn btn-default">{{ trans('admin/user-agreements/general.reset_filters') }}</a>
                    </div>
                </form>
            </div>

            <div class="box-body">
                @include('reports.procurement._report-table', [
                    'columns' => $report['columns'],
                    'rows' => $report['records'],
                    'footer' => $report['footer'],
                    'selectable' => $report['selectable'] ?? null,
                ])
            </div>
        </div>
    </div>
</div>
@stop
