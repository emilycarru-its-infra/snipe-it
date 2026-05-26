@extends('layouts/default')

@section('title')
    {{ trans('admin/reports/transactions.dashboard_title') }} — {{ $recon->period_label }}
    @parent
@stop

@section('content')

<div class="row">
    <div class="col-md-12">
        <h1 style="font-size:22px;">
            {{ trans('admin/reports/transactions.dashboard_title') }} — {{ $recon->period_label }}
            <small style="margin-left:12px; color:#888;">
                {{ trans('admin/reports/transactions.col_generated') }} {{ $recon->generated_at?->toDayDateTimeString() }}
            </small>
        </h1>

        @php $s = $recon->summary_json ?? []; @endphp
        @if (! empty($s['notes']))
            <div class="alert alert-warning">
                <strong>Notes:</strong>
                <ul style="margin:0">
                    @foreach ($s['notes'] as $n)
                        <li>{{ $n }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">{{ trans('admin/reports/transactions.period_kind_calendar') }}</h3></div>
            <div class="box-body table-responsive no-padding">
                @include('reports.transactions._gl-table', ['rows' => $glCalendar])
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">{{ trans('admin/reports/transactions.period_kind_gp_period') }}</h3></div>
            <div class="box-body table-responsive no-padding">
                @include('reports.transactions._gl-table', ['rows' => $glGp])
            </div>
        </div>
    </div>
</div>

@stop
