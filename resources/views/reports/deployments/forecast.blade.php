@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.forecast_title') }} @parent
@stop

@section('header_right')
    <a href="{{ route('reports.deployments', ['fiscal_year' => $fy]) }}" class="btn btn-sm btn-default">{{ trans('admin/deployments/general.dashboard_title') }}</a>
@stop

@section('content')

<p class="text-muted">{{ trans('admin/deployments/general.forecast_help') }}</p>

@unless ($leaseColumnPresent)
    <div class="callout callout-warning">
        <i class="fas fa-info-circle"></i> {{ trans('admin/deployments/general.forecast_lease_missing') }}
    </div>
@endunless

{{-- FY selector --}}
<div class="row">
    <div class="col-md-12">
        <form method="GET" action="{{ route('deployments.forecast') }}" class="form-inline" style="margin-bottom:15px;">
            <div class="form-group">
                <label>{{ trans('admin/deployments/general.filter_fiscal_year') }}</label>
                <select name="fiscal_year" class="form-control" onchange="this.form.submit()">
                    @foreach ($fiscalYears as $y)
                        <option value="{{ $y }}" {{ (string) $fy === (string) $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>
</div>

@if (! $fy)
    <div class="callout callout-info">{{ trans('admin/deployments/general.forecast_choose_fy') }}</div>
@else
<form method="POST" action="{{ route('deployments.forecast.add') }}">
    {{ csrf_field() }}
    <input type="hidden" name="fiscal_year" value="{{ $fy }}">

    {{-- Target wave / new wave --}}
    <div class="box box-default">
        <div class="box-body">
            <div class="form-inline">
                <div class="form-group">
                    <label>{{ trans('admin/deployments/general.target_wave') }}</label>
                    <select name="wave_id" class="form-control">
                        <option value="">—</option>
                        @foreach ($waves as $w)
                            <option value="{{ $w->id }}">{{ $w->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>{{ trans('admin/deployments/general.new_wave_name') }}</label>
                    <input type="text" name="new_wave_name" class="form-control" placeholder="{{ $fy }} Refresh">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> {{ trans('admin/deployments/general.add_from_forecast') }}</button>
            </div>
        </div>
    </div>

    {{-- Candidate assets --}}
    <div class="box box-default">
        <div class="box-body table-responsive no-padding">
            <table class="table table-hover table-striped">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" onclick="document.querySelectorAll('.fc-check').forEach(c => c.checked = this.checked);"></th>
                        <th>{{ trans('admin/deployments/general.device') }}</th>
                        <th>{{ trans('admin/deployments/general.model') }}</th>
                        <th>{{ trans('admin/deployments/general.refresh_reason') }}</th>
                        <th>{{ trans('admin/deployments/general.source_date') }}</th>
                        <th>{{ trans('general.status') }}</th>
                        <th>{{ trans('admin/deployments/general.location') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($candidates as $asset)
                    <tr>
                        <td><input type="checkbox" class="fc-check" name="asset_ids[]" value="{{ $asset->id }}" checked></td>
                        <td><a href="{{ route('hardware.show', $asset) }}">{{ $asset->name ?: $asset->asset_tag ?: ('#'.$asset->id) }}</a></td>
                        <td>{{ $asset->model?->name ?: '—' }}</td>
                        <td>
                            @php($reasonLabel = ['eol' => trans('admin/deployments/general.reason_eol'), 'lease' => trans('admin/deployments/general.reason_lease'), 'both' => trans('admin/deployments/general.reason_both')])
                            <span class="label label-default">{{ $reasonLabel[$asset->refresh_reason] ?? $asset->refresh_reason }}</span>
                        </td>
                        <td>{{ $asset->source_date ?: '—' }}</td>
                        <td>{{ $asset->status?->name ?: '—' }}</td>
                        <td>{{ $asset->location?->name ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted">{{ trans('admin/deployments/general.forecast_no_candidates') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</form>
@endif

@stop
