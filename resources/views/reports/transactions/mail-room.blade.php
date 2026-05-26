@extends('layouts/default')

@section('title')
    {{ trans('admin/reports/transactions.tab_mail_room') }}
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
        <h3 class="box-title">{{ trans('admin/reports/transactions.tab_mail_room') }} — {{ sprintf('%04d-%02d', $year, $month) }}</h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover">
            <thead><tr>
                <th>{{ trans('general.date') }}</th>
                <th>{{ trans('admin/reports/transactions.col_username') }}</th>
                <th>{{ trans('admin/reports/transactions.col_department') }}</th>
                <th>{{ trans('admin/reports/transactions.col_mapping_status') }}</th>
                <th class="text-right">{{ trans('admin/reports/transactions.col_cost') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $r)
                @php $d = $r->row_data; @endphp
                <tr>
                    <td>{{ $d['date'] ?? '' }}</td>
                    <td>{{ $d['username'] ?? '' }}</td>
                    <td>{{ $d['user department'] ?? $d['shared account name'] ?? '' }}</td>
                    <td>{{ $d['charged account'] ?? '' }}</td>
                    <td class="text-right">${{ number_format((float) ($d['cost'] ?? 0), 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted">{{ trans('admin/reports/transactions.empty_period') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@stop
