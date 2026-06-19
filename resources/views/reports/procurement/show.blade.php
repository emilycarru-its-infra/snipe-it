@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ $reportTitle }}
    @parent
@stop

{{-- Page-header actions --}}
@section('header_right')
    @if (! empty($fyFilterable))
        {{-- Carries the dashboard's fiscal-year scope; preserves any other
             report params (mode, status, …) as hidden inputs so switching
             FY doesn't drop them. --}}
        <form method="get" style="display:inline-block; margin-right:4px;">
            @foreach (($reportParams ?? []) as $paramKey => $paramValue)
                @if (! in_array($paramKey, ['fiscal_year', 'format'], true))
                    <input type="hidden" name="{{ $paramKey }}" value="{{ $paramValue }}">
                @endif
            @endforeach
            <select name="fiscal_year" class="form-control input-sm" style="display:inline-block; width:auto;" onchange="this.form.submit()">
                <option value="all" {{ ($selectedFy ?? null) === null ? 'selected' : '' }}>{{ trans('admin/purchase-orders/general.all_fiscal_years') }}</option>
                @foreach (($allFiscalYears ?? collect()) as $fy)
                    <option value="{{ $fy }}" {{ ($selectedFy ?? null) === $fy ? 'selected' : '' }}>{{ $fy }}</option>
                @endforeach
            </select>
        </form>
    @endif
    {!! $controls ?? '' !!}
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
    <a href="{{ route('reports.procurement') }}" class="btn btn-sm btn-default">
        {{ trans('admin/purchase-orders/general.reports') }}
    </a>
@stop

{{-- Page content --}}
@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-body">
                @include('reports.procurement._report-table', [
                    'columns' => $columns,
                    'rows'    => $rows,
                    'footer'  => $footer ?? null,
                    'canEditNotes' => $canEditNotes ?? false,
                ])
            </div>
        </div>
    </div>
</div>
@include('reports.procurement._report-note-js')
@stop
