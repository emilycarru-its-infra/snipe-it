@extends('layouts/default')

@section('title')
    {{ trans('admin/lease-schedules/general.annexure_diff') }} — {{ $schedule->schedule_ref }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-info">
            <strong>{{ trans('admin/lease-schedules/general.annexure_diff') }}:</strong>
            {{ trans('admin/lease-schedules/general.annexure_diff_intro') }}
        </div>

        @if (! $parserUsable)
            <div class="alert alert-warning">
                {{ trans('admin/lease-schedules/general.no_contract_field') }}
            </div>
        @endif

        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">
                    {{ $schedule->schedule_ref }} — {{ $schedule->lessor }}
                </h3>
                <div class="box-tools pull-right">
                    <span class="label label-default">
                        {{ trans('admin/lease-schedules/general.annexure_counts', ['ann_count' => $annexureCount, 'snipe_count' => $snipeCount]) }}
                    </span>
                </div>
            </div>
            <div class="box-body">
                <p class="text-muted">
                    {{ trans('general.file_name') }}: <code>{{ $upload->filename }}</code>
                    · {{ trans('general.uploaded') }} {{ optional($upload->created_at)->format('Y-m-d H:i') }}
                </p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">
                    {{ trans('admin/lease-schedules/general.annexure_matched') }}
                    <span class="badge bg-green">{{ count($matched) }}</span>
                </h3>
            </div>
            <div class="box-body">
                @if (empty($matched))
                    <p class="text-muted">{{ trans('general.no_results') }}</p>
                @else
                    <table class="table table-striped">
                        <thead><tr><th>{{ trans('general.serial') }}</th><th>{{ trans('general.asset_tag') }}</th></tr></thead>
                        <tbody>
                        @foreach ($matched as $row)
                            <tr>
                                <td><code>{{ $row['serial'] }}</code></td>
                                <td><a href="{{ route('hardware.show', $row['asset']->id) }}">{{ $row['asset']->asset_tag }}</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title">
                    {{ trans('admin/lease-schedules/general.annexure_missing_in_snipe') }}
                    <span class="badge bg-yellow">{{ count($missingInSnipe) }}</span>
                </h3>
            </div>
            <div class="box-body">
                <p class="text-muted">{{ trans('admin/lease-schedules/general.annexure_missing_in_snipe_hint') }}</p>
                @if (empty($missingInSnipe))
                    <p class="text-muted">{{ trans('general.no_results') }}</p>
                @else
                    <ul style="padding-left: 18px;">
                        @foreach ($missingInSnipe as $serial)
                            <li><code>{{ $serial }}</code></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="box box-danger">
            <div class="box-header with-border">
                <h3 class="box-title">
                    {{ trans('admin/lease-schedules/general.annexure_missing_in_annexure') }}
                    <span class="badge bg-red">{{ $missingInAnnexure->count() }}</span>
                </h3>
            </div>
            <div class="box-body">
                <p class="text-muted">{{ trans('admin/lease-schedules/general.annexure_missing_in_annexure_hint') }}</p>
                @if ($missingInAnnexure->isEmpty())
                    <p class="text-muted">{{ trans('general.no_results') }}</p>
                @else
                    <table class="table table-striped">
                        <thead><tr><th>{{ trans('general.serial') }}</th><th>{{ trans('general.asset_tag') }}</th></tr></thead>
                        <tbody>
                        @foreach ($missingInAnnexure as $asset)
                            <tr>
                                <td><code>{{ $asset->serial }}</code></td>
                                <td><a href="{{ route('hardware.show', $asset->id) }}">{{ $asset->asset_tag }}</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>
@stop
