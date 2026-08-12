{{-- The decommissioning lane — included by the board (bottom section)
     and by the dedicated /deployments/decommissioning page. Needs
     $decommission, $isPast and $fy; the dp-* rail/scroll styles come
     from the host page. --}}
{{-- Decommissioning — the reverse flow, current + past years only (a
     future year has no outgoing work yet). Collecting is split into
     buckets per Processing kind — returns, donations and recycling are
     handled by different parties. The pickups register groups
     decommissioned devices by decommission date: each date is one
     physical run, with its own targeted CSV. --}}
@if ($decommission)
<div class="box box-default" id="decommissioning" style="scroll-margin-top:64px;">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.decom_title') }}
            <a href="#decommissioning" class="text-muted" style="font-size:13px;" title="{{ trans('admin/deployments/general.decom_permalink') }}"><i class="fas fa-link"></i></a>
        </h3>
        <span class="text-muted" style="font-size:12px; margin-left:10px;">{{ trans('admin/deployments/general.decom_hint') }}</span>
        <div class="box-tools pull-right">
            <a href="{{ route('reports.procurement.disposition-grid') }}" class="btn btn-sm btn-default">
                {{ trans('admin/deployments/general.decom_open_disposition') }}
            </a>
        </div>
    </div>
    <div class="box-body">
        <div class="dp-rail-scroll">
            <div class="dp-rail" style="min-width:520px;">
                @php($decomStages = array_values(array_filter([
                    $isPast ? null : ['label' => trans('admin/deployments/general.decom_collecting'), 'note' => trans('admin/deployments/general.decom_collecting_note'), 'count' => $decommission['collectingCount'], 'color' => '#1f9e8e'],
                    ['label' => trans('admin/deployments/general.decom_decommissioned'), 'note' => trans('admin/deployments/general.decom_decommissioned_note'), 'count' => $decommission['decommissionedCount'], 'color' => '#c8860a'],
                ])))
                @foreach ($decomStages as $ds)
                    <div class="dp-chev" style="--dp-c: {{ $ds['color'] }}; cursor:default;">
                        <div class="dp-stage">{{ $ds['label'] }}</div>
                        <div class="dp-big">{{ $ds['count'] }}</div>
                        <div class="text-muted" style="font-size:11.5px; line-height:1.35; margin-top:2px;">{{ $ds['note'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
        @if ($decommission['unarchivedCount'] > 0)
            <p class="text-muted" style="font-size:12px; margin:6px 0 0;">
                {{ trans('admin/deployments/general.decom_unarchived_note', ['count' => $decommission['unarchivedCount']]) }}
            </p>
        @endif

        @if (! $isPast)
            @if ($decommission['collectingCount'] === 0)
                <p class="text-muted" style="margin:10px 0 0;">{{ trans('admin/deployments/general.decom_none') }}</p>
            @else
                <div class="row" style="margin-top:15px;">
                    <div class="col-md-9">
                        @foreach ($decommission['buckets'] as $bucket)
                            <form method="POST" action="{{ route('deployments.decommission.location') }}">
                                @csrf
                                <h5 style="font-weight:700; margin:{{ $loop->first ? '0' : '18px' }} 0 6px;">
                                    {{ $bucket['label'] }}
                                    <span class="text-muted" style="font-weight:normal;">· {{ $bucket['count'] }}</span>
                                    <span class="pull-right" style="font-weight:normal;">
                                        <select class="js-data-ajax input-sm" data-endpoint="locations" data-placeholder="{{ trans('admin/deployments/general.holding_location_label') }}" name="location_id" style="min-width:220px;"></select>
                                        <button type="submit" class="btn btn-xs btn-default">{{ trans('admin/deployments/general.holding_location_apply') }}</button>
                                    </span>
                                </h5>
                                <div class="dp-scroll" style="max-height:360px;">
                                    <table class="table table-striped table-condensed" style="margin-bottom:0;">
                                        <thead>
                                            <tr>
                                                <th style="width:28px;"></th>
                                                <th>{{ trans('admin/deployments/general.decom_col_asset') }}</th>
                                                <th>{{ trans('admin/deployments/general.decom_col_model') }}</th>
                                                <th>{{ trans('admin/deployments/general.decom_col_status') }}</th>
                                                <th>{{ trans('admin/deployments/general.decom_col_location') }}</th>
                                                <th>{{ trans('admin/purchase-orders/general.lease_provider') }}</th>
                                                <th>{{ trans('admin/deployments/general.decom_col_lease_end') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($bucket['rows'] as $row)
                                                <tr>
                                                    <td><input type="checkbox" name="asset_ids[]" value="{{ $row['id'] }}"></td>
                                                    <td><a href="{{ route('hardware.show', $row['id']) }}" class="js-lightbox">{{ $row['asset_tag'] }}</a></td>
                                                    <td>{{ $row['model'] ?: '—' }}</td>
                                                    <td>{{ $row['status'] ?: '—' }}</td>
                                                    <td>{{ $row['location'] ?: '—' }}</td>
                                                    <td>{{ $row['lessor'] ?: '—' }}</td>
                                                    <td>{{ $row['lease_end_date'] ?: '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </form>
                        @endforeach
                    </div>
                    <div class="col-md-3">
                        <h5 style="margin-top:0; font-weight:700;">{{ trans('admin/deployments/general.decom_locations') }}</h5>
                        <table class="table table-condensed" style="margin-bottom:10px;">
                            <tbody>
                                @foreach ($decommission['byLocation'] as $loc)
                                    <tr>
                                        <td>{{ $loc['location'] }}</td>
                                        <td class="text-right"><strong>{{ $loc['count'] }}</strong></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @foreach ($decommission['statuses'] as $status)
                            @if ($status['count'] > 0)
                                <span class="label" style="background-color:#1f9e8e; color:#fff; display:inline-block; margin:0 4px 4px 0;">
                                    {{ $status['name'] }} · {{ $status['count'] }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        @endif

        {{-- Pickups register: the way back through what actually left. --}}
        <h5 style="margin-top:18px; font-weight:700;">
            {{ trans('admin/deployments/general.decom_pickups_title') }}
            <span class="text-muted" style="font-weight:normal; font-size:12px; margin-left:6px;">{{ trans('admin/deployments/general.decom_pickups_hint') }}</span>
        </h5>
        @if (count($decommission['pickups']) === 0)
            <p class="text-muted" style="margin:6px 0 0;">{{ trans('admin/deployments/general.decom_no_pickups') }}</p>
        @else
            <div class="dp-scroll" style="max-height:360px;">
                <table class="table table-striped table-condensed" style="margin-bottom:0;">
                    <thead>
                        <tr>
                            <th>{{ trans('admin/deployments/general.pickup_col_date') }}</th>
                            <th class="text-right">{{ trans('admin/deployments/general.pickup_col_devices') }}</th>
                            <th>{{ trans('admin/deployments/general.pickup_col_models') }}</th>
                            <th>{{ trans('admin/deployments/general.pickup_col_locations') }}</th>
                            <th>{{ trans('admin/deployments/general.pickup_col_lessors') }}</th>
                            <th style="width:70px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($decommission['pickups'] as $pickup)
                            <tr>
                                <td>{{ $pickup['date'] }}</td>
                                <td class="text-right"><strong>{{ $pickup['count'] }}</strong></td>
                                <td>{{ $pickup['models'] }}</td>
                                <td>{{ $pickup['locations'] }}</td>
                                <td>{{ $pickup['lessors'] ?: '—' }}</td>
                                <td>
                                    <a href="{{ route('reports.deployments', ['fiscal_year' => $fy, 'decom_pickup' => $pickup['date'], 'format' => 'csv']) }}" class="btn btn-xs btn-default">
                                        <i class="fas fa-download"></i> {{ trans('admin/deployments/general.pickup_csv') }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endif

