@extends('layouts/default')

@section('title')
    {{ trans('admin/reports/printing.dashboard_title') }}
    @parent
@stop

@section('content')

<div class="row">
    <div class="col-md-12" style="margin-bottom:15px; color:#888;">
        {{ trans('admin/reports/printing.period_label') }}:
        <strong>{{ $periodLabel }}</strong>
    </div>
</div>

<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/reports/printing.dashboard_title') }}</h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('admin/reports/printing.col_asset') }}</th>
                    <th>{{ trans('admin/reports/printing.col_department') }}</th>
                    <th>{{ trans('admin/reports/printing.col_last_seen') }}</th>
                    <th class="text-right">{{ trans('admin/reports/printing.col_jobs') }}</th>
                    <th class="text-right">{{ trans('admin/reports/printing.col_pages') }}</th>
                    <th class="text-right">{{ trans('admin/reports/printing.col_cost') }}</th>
                    <th class="text-right">{{ trans('admin/reports/printing.col_refund_rate') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td>
                        <a href="{{ route('hardware.show', $r['asset']) }}#printing">
                            {{ $r['asset']->name ?: $r['asset']->asset_tag }}
                        </a>
                        <small style="color:#999;">{{ $r['asset']->asset_tag }}</small>
                    </td>
                    <td>{{ $r['department'] }}</td>
                    <td>
                        @if ($r['last_seen'])
                            <span title="{{ $r['last_seen']->toDateTimeString() }}">{{ $r['last_seen']->diffForHumans() }}</span>
                        @else
                            <span class="text-muted">{{ trans('admin/reports/printing.never') }}</span>
                        @endif
                    </td>
                    <td class="text-right">{{ number_format($r['jobs']) }}</td>
                    <td class="text-right">{{ number_format($r['pages']) }}</td>
                    <td class="text-right">{{ Helper::formatCurrencyOutput($r['cost']) }}</td>
                    <td class="text-right">
                        @if ($r['jobs'] > 0)
                            <span class="@if ($r['refund_rate'] > 0.1) text-danger @endif">
                                {{ number_format($r['refund_rate'] * 100, 1) }}%
                            </span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center" style="padding:28px; color:#888;">
                        {{ trans('admin/reports/printing.no_printers') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@stop
