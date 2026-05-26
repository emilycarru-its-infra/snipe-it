@extends('layouts/default')

@section('title')
    {{ trans('admin/reports/transactions.tab_refunds') }}
    @parent
@stop

@section('content')

<form method="get" class="form-inline" style="margin-bottom:15px;">
    <label>{{ trans('admin/reports/transactions.col_period') }}:</label>
    <input type="number" name="year"  value="{{ $year }}"  class="form-control input-sm" style="width:90px"/>
    <input type="number" name="month" value="{{ $month }}" min="1" max="12" class="form-control input-sm" style="width:70px"/>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
</form>

<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/reports/transactions.tab_refunds') }} — {{ sprintf('%04d-%02d', $year, $month) }}</h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover">
            <thead><tr>
                <th>{{ trans('admin/reports/transactions.col_transaction_type') }}</th>
                <th class="text-right">{{ trans('admin/reports/transactions.col_credit') }}</th>
                <th class="text-right">{{ trans('admin/reports/transactions.col_debit') }}</th>
                <th class="text-right">{{ trans('admin/reports/transactions.col_net') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $r)
                @php $d = $r->row_data; @endphp
                <tr>
                    <td>{{ $d['transaction type'] ?? '' }}</td>
                    <td class="text-right">${{ number_format((float) ($d['credit'] ?? 0), 2) }}</td>
                    <td class="text-right">${{ number_format((float) ($d['debit']  ?? 0), 2) }}</td>
                    <td class="text-right">${{ number_format((float) ($d['net']    ?? 0), 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted">{{ trans('admin/reports/transactions.empty_period') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@stop
