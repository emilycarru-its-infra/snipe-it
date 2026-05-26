@extends('layouts/default')

@section('title')
    Self-Serve Print Report — {{ sprintf('%04d-%02d', $year, $month) }}
    @parent
@stop

@section('content')

<form method="get" class="form-inline" style="margin-bottom:15px;">
    <label>{{ trans('admin/reports/transactions.col_period') }}:</label>
    <input type="number" name="year"  value="{{ $year }}"  class="form-control input-sm" style="width:90px"/>
    <input type="number" name="month" value="{{ $month }}" min="1" max="12" class="form-control input-sm" style="width:70px"/>
    <button class="btn btn-sm btn-primary" type="submit">{{ trans('general.filter') }}</button>
</form>

<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">Self-Serve Print Report — {{ sprintf('%04d-%02d', $year, $month) }}</h3>
        <p class="box-subtitle" style="margin:6px 0 0; color:#aaa; font-size:12px;">
            Per-GL journal-transfer breakdown — the single output Finance posts to Colleague.
        </p>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover">
            <thead><tr>
                <th>Department</th>
                <th class="text-right">Amount</th>
            </tr></thead>
            <tbody>
                @php $grand = 0; @endphp
                @forelse ($rows as $r)
                    @php $grand += (float) $r->amount; @endphp
                    <tr>
                        <td>{{ ucwords(str_replace(['revenue_', '_'], ['', ' '], $r->line_key)) }}</td>
                        <td class="text-right">${{ number_format((float) $r->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="text-center text-muted">
                        {{ trans('admin/reports/transactions.empty_period') }}
                    </td></tr>
                @endforelse
            </tbody>
            @if ($rows->count())
            <tfoot>
                <tr>
                    <th>Total</th>
                    <th class="text-right">${{ number_format($grand, 2) }}</th>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

@stop
