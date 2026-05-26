@extends('layouts/default')

@section('title')
    Line Items — {{ sprintf('%04d-%02d', $year, $month) }}
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
        <h3 class="box-title">Effective Line Items — {{ sprintf('%04d-%02d', $year, $month) }}</h3>
        <p class="box-subtitle" style="margin:6px 0 0; color:#aaa; font-size:12px;">
            Every cell that the two final Reconcile tabs read from. Override (blue) wins over derived (grey).
        </p>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover">
            <thead><tr>
                <th>Line key</th>
                <th class="text-right">Amount</th>
                <th>Source</th>
                <th>Set by</th>
                <th>Note</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td><code>{{ $r->line_key }}</code></td>
                    <td class="text-right">${{ number_format((float) $r->amount, 2) }}</td>
                    <td>
                        @if ($r->source === 'override')
                            <span class="label label-primary">override</span>
                        @else
                            <span class="label label-default">derived</span>
                        @endif
                    </td>
                    <td>{{ $r->override_set_by }}</td>
                    <td>{{ $r->override_note }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted">
                    {{ trans('admin/reports/transactions.empty_period') }}
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@stop
