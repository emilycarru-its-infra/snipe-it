@extends('layouts/default')

@section('title')
    {{ $reportTitle }}
    @parent
@stop

@section('header_right')
    <form method="get" class="form-inline" style="display:inline-block; margin-right:6px;">
        <select name="fiscal_year" class="form-control input-sm" onchange="this.form.submit()">
            @foreach ($fiscalYears as $fy)
                <option value="{{ $fy }}" @selected($selectedFy === $fy)>{{ $fy }}</option>
            @endforeach
            <option value="all" @selected($selectedFy === null)>{{ trans('general.all') }}</option>
        </select>
    </form>
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default"><x-icon type="download"/> {{ trans('general.download') }}</a>
@stop

@section('content')

@php $t = fn ($k, $r = []) => trans('admin/purchase-orders/general.'.$k, $r); @endphp

<div class="row">
    <div class="col-md-12">

        {{-- Discrepancies — the point of the report. Always visible, loud
             when present, a quiet all-clear when not. --}}
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ $t('csi_recon_discrepancies') }}</h3>
                <div class="box-tools pull-right"><span class="text-muted" style="font-size:12px;">{{ $summary }}</span></div>
            </div>
            <div class="box-body">
                @if (count($grouped['discrepancies']) === 0)
                    <p style="margin:0;"><x-icon type="checkmark" class="text-success"/> {{ $t('csi_recon_no_discrepancies') }}</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>{{ $t('csi_recon_status') }}</th>
                                    <th>{{ $t('csi_recon_serial') }}</th>
                                    <th>{{ $t('csi_recon_model') }}</th>
                                    <th>{{ $t('csi_recon_csi_schedule') }}</th>
                                    <th>{{ $t('csi_recon_snipe_schedule') }}</th>
                                    <th>{{ $t('csi_recon_snipe_tag') }}</th>
                                    <th>{{ $t('csi_recon_snipe_status') }}</th>
                                    <th>{{ $t('csi_recon_snipe_assigned') }}</th>
                                    <th>{{ $t('csi_recon_snipe_location') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($grouped['discrepancies'] as $row)
                                    <tr class="danger">
                                        <td>{{ $t('csi_recon_'.$row['status']) }}</td>
                                        <td class="ecu-tag">{{ $row['serial'] }}</td>
                                        <td>{{ $row['model'] }}</td>
                                        <td>{{ $row['csi_schedule'] }}</td>
                                        <td>{{ $row['snipe_schedule'] }}</td>
                                        <td>
                                            @if ($row['asset_id'])
                                                <a href="{{ route('hardware.show', $row['asset_id']) }}">{{ $row['snipe_tag'] }}</a>
                                            @else — @endif
                                        </td>
                                        <td>{{ $row['snipe_status'] ?: '—' }}</td>
                                        <td>{{ $row['snipe_assigned'] ?: '—' }}</td>
                                        <td>{{ $row['snipe_location'] ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- Unserialized financed lines — informational, never a red flag. --}}
        @if (count($grouped['unserialized']))
            <details class="box box-default recon-fold">
                <summary class="box-header with-border">
                    <h3 class="box-title">{{ $t('csi_recon_unserialized_fold', ['count' => count($grouped['unserialized'])]) }}</h3>
                </summary>
                <div class="box-body">
                    <table class="table table-condensed" style="max-width: 640px;">
                        @foreach ($grouped['unserialized'] as $row)
                            <tr>
                                <td>{{ $row['model'] }}</td>
                                <td>{{ $row['csi_schedule'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </details>
        @endif

        {{-- Matches, folded: they prove the reconciliation, they don't need
             reading. Open on demand with the full live-asset detail. --}}
        <details class="box box-default recon-fold">
            <summary class="box-header with-border">
                <h3 class="box-title"><x-icon type="checkmark" class="text-success"/> {{ $t('csi_recon_matches_fold', ['count' => count($grouped['matches'])]) }}</h3>
            </summary>
            <div class="box-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>{{ $t('csi_recon_serial') }}</th>
                                <th>{{ $t('csi_recon_model') }}</th>
                                <th>{{ $t('csi_recon_csi_schedule') }}</th>
                                <th>{{ $t('csi_recon_snipe_tag') }}</th>
                                <th>{{ $t('csi_recon_snipe_status') }}</th>
                                <th>{{ $t('csi_recon_snipe_assigned') }}</th>
                                <th>{{ $t('csi_recon_snipe_location') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($grouped['matches'] as $row)
                                <tr>
                                    <td class="ecu-tag">{{ $row['serial'] }}</td>
                                    <td>{{ $row['snipe_model'] ?: $row['model'] }}</td>
                                    <td>{{ $row['csi_schedule'] }}</td>
                                    <td>
                                        @if ($row['asset_id'])
                                            <a href="{{ route('hardware.show', $row['asset_id']) }}">{{ $row['snipe_tag'] }}</a>
                                        @else {{ $row['snipe_tag'] }} @endif
                                    </td>
                                    <td>{{ $row['snipe_status'] ?: '—' }}</td>
                                    <td>{{ $row['snipe_assigned'] ?: '—' }}</td>
                                    <td>{{ $row['snipe_location'] ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </details>

    </div>
</div>

<style>
    .recon-fold > summary { cursor: pointer; list-style: none; }
    .recon-fold > summary::-webkit-details-marker { display: none; }
    .recon-fold > summary .box-title::after {
        content: "›";
        display: inline-block;
        margin-left: 8px;
        transform: rotate(90deg);
        transition: transform .12s ease;
        color: var(--chrome-fg-muted, #8a8a92);
    }
    .recon-fold[open] > summary .box-title::after { transform: rotate(-90deg); }
</style>

@stop
