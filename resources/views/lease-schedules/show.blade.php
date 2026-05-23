@extends('layouts/default')

@section('title')
    {{ $schedule->schedule_ref }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ $schedule->schedule_ref }} — {{ $schedule->lessor }}</h3>
                <div class="box-tools pull-right">
                    @if ($schedule->vendor_on_hold)
                        <span class="label label-danger">{{ trans('admin/lease-schedules/general.vendor_on_hold') }}</span>
                    @endif
                    <span class="label label-default">{{ trans('admin/purchase-orders/general.schedule_stage_'.$schedule->lifecycle_stage) }}</span>
                </div>
            </div>
            <div class="box-body">
                <dl class="dl-horizontal">
                    <dt>{{ trans('admin/lease-schedules/general.lease_type') }}</dt>
                    <dd>{{ $schedule->lease_type ?? '—' }}{{ $schedule->term_months ? ' / '.$schedule->term_months.' mo' : '' }}</dd>
                    <dt>{{ trans('admin/lease-schedules/general.received_at') }}</dt>
                    <dd>{{ optional($schedule->received_at)->format('Y-m-d') ?? '—' }}</dd>
                    <dt>{{ trans('admin/lease-schedules/general.expected_acquisition_cost') }}</dt>
                    <dd>${{ number_format((float) $schedule->expected_acquisition_cost, 2) }}</dd>
                    <dt>{{ trans('admin/lease-schedules/general.expected_asset_count') }}</dt>
                    <dd>{{ (int) ($schedule->expected_asset_count ?? 0) }}</dd>
                    <dt>{{ trans('admin/lease-schedules/general.usage_tag') }}</dt>
                    <dd>{{ $schedule->usage_tag ?? '—' }}</dd>
                    <dt>{{ trans('admin/lease-schedules/general.signed_at') }}</dt>
                    <dd>{{ $schedule->signed_at ? $schedule->signed_at->format('Y-m-d H:i') : '—' }}</dd>
                    <dt>{{ trans('admin/lease-schedules/general.signed_by') }}</dt>
                    <dd>{{ $schedule->signer?->full_name ?? '—' }}</dd>
                    @if ($schedule->annexure_a_path)
                        <dt>{{ trans('admin/lease-schedules/general.annexure_a_path') }}</dt>
                        <dd><code>{{ $schedule->annexure_a_path }}</code></dd>
                    @endif
                    @if ($schedule->notes)
                        <dt>{{ trans('admin/lease-schedules/general.notes') }}</dt>
                        <dd>{!! nl2br(e($schedule->notes)) !!}</dd>
                    @endif
                </dl>
            </div>
            <div class="box-footer">
                <a class="btn btn-warning" href="{{ route('lease-schedules.edit', $schedule) }}">
                    <i class="fas fa-pencil-alt"></i> {{ trans('general.update') }}
                </a>
                @if (! $schedule->isSigned())
                    <form method="POST" action="{{ route('lease-schedules.mark-signed', $schedule) }}" class="pull-right" style="display:inline-block;">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-stamp"></i> {{ trans('admin/lease-schedules/general.mark_signed') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@stop
