@extends('layouts/default')

@section('title')
    {{ trans('admin/reports/transactions.tab_gl_breakdown') }}
    @parent
@stop

@section('content')

<form method="get" class="form-inline" style="margin-bottom:15px;">
    <label>{{ trans('admin/reports/transactions.col_period') }}:</label>
    <input type="number" name="year" value="{{ $year }}" class="form-control input-sm" style="width:90px"/>
    <input type="number" name="month" value="{{ $month }}" min="1" max="12" class="form-control input-sm" style="width:70px"/>
    <select name="kind" class="form-control input-sm">
        <option value="calendar"  {{ $kind === 'calendar' ? 'selected' : '' }}>{{ trans('admin/reports/transactions.period_kind_calendar') }}</option>
        <option value="gp_period" {{ $kind === 'gp_period' ? 'selected' : '' }}>{{ trans('admin/reports/transactions.period_kind_gp_period') }}</option>
    </select>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
</form>

<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">
            {{ trans('admin/reports/transactions.tab_gl_breakdown') }}
            — {{ sprintf('%04d-%02d', $year, $month) }}
        </h3>
    </div>
    <div class="box-body table-responsive no-padding">
        @include('reports.transactions._gl-table', ['rows' => $rows])
    </div>
</div>

@stop
