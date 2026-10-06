{{-- The decommissioning lane — included by the board (bottom section)
     and by the dedicated /deployments/decommissioning page. Needs
     $decommission, $isPast and $fy; the dp-* rail/scroll styles come
     from the host page.

     Distinct cards, one per flow, in reading order: Buyouts, Returns,
     Donations, Recycling — then the pickups register. Every device row
     edits in place with the asset-page pencil pattern (status, home
     location, lease end), so working the lane never means leaving it. --}}
@if ($decommission)
@once
@push('css')
<style>
    {{-- Pencils hide until their row is hovered — the same affordance as
         the asset page's cards, retargeted at these tables' rows. --}}
    .decom-card tbody tr:hover .inline-core-pencil { opacity: .6; }
    .decom-card tbody tr:hover .inline-core-pencil:hover { opacity: 1; }
    .decom-card .js-inline-edit-form { white-space: nowrap; }

    {{-- Holding rooms read as quiet chips, not as status badges: they label a
         place, they do not signal a state, and a saturated pill claimed more
         attention than a room number is worth. --}}
    .decom-chip {
        display: inline-block; margin: 0 6px 6px 0; padding: 3px 6px 3px 9px;
        border: 1px solid var(--box-border-color, #e3e3e3); border-radius: 3px;
        background: var(--box-bg, #fff); font-size: 12px; line-height: 1.5;
        color: var(--color-fg, #444);
    }
    .decom-chip-n {
        display: inline-block; margin-left: 5px; padding: 0 6px;
        border-radius: 2px; background: rgba(127, 127, 127, .14);
        font-variant-numeric: tabular-nums; font-weight: 700;
    }

    {{-- The bulk holding-location control sits with the card title on the
         left, where the eye already is after reading the count. --}}
    .decom-card .decom-card-head {
        display: flex; align-items: center; flex-wrap: wrap; gap: 8px;
    }
    .decom-card .decom-card-head .box-title { margin-right: 4px; }
</style>
@endpush
@push('js')
<script nonce="{{ csrf_token() }}">
    // Pencil swaps the value for its single-field form; cancel swaps back.
    // Same contract as the asset page's inline editors.
    $(function () {
        $(document).on('click', '.js-inline-edit-toggle', function (e) {
            e.preventDefault();
            var target = $(this).data('target');
            $('#' + target + '-display').hide();
            $('#' + target + '-form').show()
                .find('input[name="value"], textarea[name="value"], select[name="value"]').first().trigger('focus');
        });
        $(document).on('click', '.js-inline-edit-cancel', function (e) {
            e.preventDefault();
            var target = $(this).data('target');
            $('#' + target + '-form').hide();
            $('#' + target + '-display').show();
        });
    });
</script>
@endpush
@endonce
@php
    $decomStatusOptions = \App\Models\Statuslabel::orderBy('name')->pluck('name', 'id');
    $decomLocationOptions = \App\Models\Location::orderBy('name')->pluck('name', 'id');
@endphp

{{-- The rollup: stage chevrons, and where the outgoing devices sit. --}}
<div class="box box-default" id="decommissioning" style="scroll-margin-top:64px;">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.decom_title') }}@if ($flow ?? null) · {{ trans('admin/deployments/general.decom_bucket_'.$flow) }}@endif
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
                    ($flow ?? null) ? null : ['label' => trans('admin/deployments/general.decom_buyouts'), 'note' => trans('admin/deployments/general.decom_buyouts_note'), 'count' => $decommission['buyouts']['openCount'], 'color' => '#4f6d7a'],
                    ($flow ?? null) === 'returns' ? ['label' => trans('admin/deployments/general.lease_pickups_title'), 'note' => trans('admin/deployments/general.lease_pickups_hint'), 'count' => collect($decommission['leasePickups'])->filter(fn ($p) => $p->isOpen())->count(), 'color' => '#4f6d7a'] : null,
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

        {{-- Only the holding rooms. The Processing-status counts that used to
             sit beside them are the same numbers the per-flow cards below
             already carry in their own headers, said twice. --}}
        @unless ($isPast)
            <div style="margin-top:12px;">
                <h5 style="margin:0 0 6px; font-weight:700;">{{ trans('admin/deployments/general.decom_locations') }}</h5>
                @forelse ($decommission['byLocation'] as $loc)
                    <span class="decom-chip">{{ $loc['location'] }} <span class="decom-chip-n">{{ $loc['count'] }}</span></span>
                @empty
                    <span class="text-muted">{{ trans('admin/deployments/general.decom_none') }}</span>
                @endforelse
            </div>
        @endunless
    </div>
</div>

{{-- Buyouts: the devices leaving by purchase rather than by pickup. --}}
@unless ($flow ?? null)
@include('reports.deployments._buyouts', ['buyouts' => $decommission['buyouts']])
@endunless

{{-- One card per collecting flow — returns, donations, recycling are
     handled by different parties, so each reads as its own register. --}}
@unless ($isPast)
    @foreach ($decommission['buckets'] as $bucket)
        @continue(($flow ?? null) && $bucket['key'] !== $flow)
        {{-- The bulk holding-location form lives outside the table so the
             row-level inline-edit forms never nest inside it; checkboxes
             reach it through the form attribute, queue-page style. --}}
        <form method="POST" action="{{ route('deployments.decommission.location') }}" id="decom-loc-{{ $bucket['key'] }}">
            @csrf
        </form>
        <div class="box box-default decom-card">
            <div class="box-header with-border decom-card-head">
                <h3 class="box-title">{{ $bucket['label'] }}
                    <span class="text-muted" style="font-weight:normal; font-size:13px;">· {{ $bucket['count'] }}</span>
                </h3>
                <select class="js-data-ajax input-sm" data-endpoint="locations" form="decom-loc-{{ $bucket['key'] }}"
                        data-placeholder="{{ trans('admin/deployments/general.holding_location_label') }}" name="location_id" style="min-width:220px;"></select>
                <button type="submit" form="decom-loc-{{ $bucket['key'] }}" class="btn btn-xs btn-default">{{ trans('admin/deployments/general.holding_location_apply') }}</button>
                {{-- Returns go back to a lessor, so the same ticked rows can
                     be bundled into a pickup request instead. The button
                     re-targets the card's form; no second set of checkboxes. --}}
                @if ($bucket['key'] === 'returns')
                    @can('deployments.edit')
                        <span style="display:inline-block; margin-left:14px;">
                            <input type="text" name="preferred_dates" maxlength="191" form="decom-loc-{{ $bucket['key'] }}" class="form-control input-sm"
                                   style="display:inline-block; width:230px;" placeholder="{{ trans('admin/deployments/general.pickup_preferred_placeholder') }}">
                            <input type="text" name="pickup_notes" maxlength="2000" form="decom-loc-{{ $bucket['key'] }}" class="form-control input-sm"
                                   style="display:inline-block; width:200px;" placeholder="{{ trans('admin/deployments/general.pickup_notes_placeholder') }}">
                            <label style="font-weight:normal; font-size:12px; margin:0 6px;">
                                <input type="checkbox" name="confirmed_ready" value="1" form="decom-loc-{{ $bucket['key'] }}">
                                {{ trans('admin/deployments/general.pickup_confirm_ready') }}
                            </label>
                            <button type="submit" form="decom-loc-{{ $bucket['key'] }}" formaction="{{ route('lease-pickups.store') }}" class="btn btn-xs btn-primary">
                                <i class="fas fa-truck"></i> {{ trans('admin/deployments/general.pickup_request_button') }}
                            </button>
                        </span>
                    @endcan
                @endif
            </div>
            <div class="box-body table-responsive no-padding">
                <div class="dp-scroll" style="max-height:420px;">
                    <table class="table table-striped table-condensed" style="margin-bottom:0;">
                        <thead>
                            <tr>
                                <th style="width:28px;"></th>
                                <th>{{ trans('admin/deployments/general.decom_col_asset') }}</th>
                                <th>{{ trans('admin/deployments/general.decom_col_status') }}</th>
                                <th>{{ trans('admin/deployments/general.decom_col_location') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.lease_provider') }}</th>
                                <th>{{ trans('admin/deployments/general.decom_col_lease_end') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bucket['rows'] as $row)
                                <tr>
                                    <td><input type="checkbox" name="asset_ids[]" value="{{ $row['id'] }}" form="decom-loc-{{ $bucket['key'] }}"></td>
                                    <td>
                                        <a href="{{ route('hardware.show', $row['id']) }}" class="js-lightbox">{{ $row['asset_tag'] }}</a>
                                        <span class="text-muted" style="font-size:11.5px;">{{ $row['model'] ?: '' }}</span>
                                        @if ($row['pickup_id'])
                                            <span class="label label-info" style="margin-left:4px;">{{ trans('admin/deployments/general.pickup_on', ['id' => $row['pickup_id']]) }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <x-inline-core-field :asset="$row['asset']" column="status_id" element="select" :options="$decomStatusOptions">{{ $row['status'] ?: '—' }}</x-inline-core-field>
                                    </td>
                                    <td>
                                        <x-inline-core-field :asset="$row['asset']" column="rtd_location_id" element="select" :options="$decomLocationOptions">{{ $row['location'] ?: '—' }}</x-inline-core-field>
                                    </td>
                                    <td>{{ $row['lessor'] ?: '—' }}</td>
                                    <td>
                                        <x-inline-core-field :asset="$row['asset']" column="lease_end_date" element="date">{{ $row['lease_end_date'] ?: '—' }}</x-inline-core-field>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
    @if ($decommission['collectingCount'] === 0 || (($flow ?? null) && ! collect($decommission['buckets'])->contains('key', $flow)))
        <div class="box box-default decom-card">
            <div class="box-body"><p class="text-muted" style="margin:0;">{{ trans('admin/deployments/general.decom_none') }}</p></div>
        </div>
    @endif
@endunless

{{-- Pickups requested: what each lessor has been asked to collect, and
     what they answered. One row per request; the answer is typed in here
     rather than left in the mail thread. --}}
@if (count($decommission['leasePickups']) > 0 && in_array($flow ?? null, [null, 'returns'], true))
<div class="box box-default decom-card">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.lease_pickups_title') }}
            <span class="text-muted" style="font-weight:normal; font-size:12px; margin-left:8px;">{{ trans('admin/deployments/general.lease_pickups_hint') }}</span>
        </h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-striped table-condensed" style="margin-bottom:0;">
            <thead>
                <tr>
                    <th>#</th>
                    <th>{{ trans('admin/deployments/general.lease_pickups_col_lessor') }}</th>
                    <th class="text-right">{{ trans('admin/deployments/general.pickup_col_devices') }}</th>
                    <th>{{ trans('admin/deployments/general.lease_pickups_col_schedules') }}</th>
                    <th>{{ trans('admin/deployments/general.lease_pickups_col_requested') }}</th>
                    <th>{{ trans('admin/deployments/general.decom_col_status') }}</th>
                    <th>{{ trans('admin/deployments/general.lease_pickups_col_load') }} / {{ trans('admin/deployments/general.lease_pickups_col_scheduled') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($decommission['leasePickups'] as $leasePickup)
                    <tr>
                        <td>{{ $leasePickup->id }}</td>
                        <td>{{ $leasePickup->lessor?->name ?: '—' }}</td>
                        <td class="text-right"><strong>{{ $leasePickup->assets->count() }}</strong></td>
                        <td>
                            @foreach ($leasePickup->scheduleCounts() as $schedule => $count)
                                <span class="decom-chip">{{ $schedule }} <span class="decom-chip-n">{{ $count }}</span></span>
                            @endforeach
                        </td>
                        <td>
                            {{ $leasePickup->requested_at?->toDateString() }}
                            @if ($leasePickup->preferred_dates)
                                <div class="text-muted" style="font-size:11.5px;">{{ $leasePickup->preferred_dates }}</div>
                            @endif
                        </td>
                        <td>{{ trans('admin/deployments/general.lease_pickups_status_'.$leasePickup->status) }}
                            @if ($leasePickup->picked_up_at)
                                <div class="text-muted" style="font-size:11.5px;">{{ $leasePickup->picked_up_at->toDateString() }}</div>
                            @endif
                        </td>
                        <td>
                            @if ($leasePickup->isOpen() && Gate::allows('deployments.edit'))
                                <form method="POST" action="{{ route('lease-pickups.schedule', $leasePickup) }}" class="form-inline">
                                    @csrf
                                    <input type="text" name="load_number" value="{{ $leasePickup->load_number }}" class="form-control input-sm" style="width:90px;" placeholder="{{ trans('admin/deployments/general.lease_pickups_col_load') }}">
                                    <input type="date" name="scheduled_date" value="{{ $leasePickup->scheduled_date?->toDateString() }}" class="form-control input-sm">
                                    <input type="text" name="scheduled_window" value="{{ $leasePickup->scheduled_window }}" class="form-control input-sm" style="width:100px;" placeholder="{{ trans('admin/deployments/general.lease_pickups_window_placeholder') }}">
                                    <button type="submit" class="btn btn-xs btn-default">{{ trans('admin/deployments/general.lease_pickups_save') }}</button>
                                </form>
                            @else
                                {{ $leasePickup->load_number ?: '—' }}
                                @if ($leasePickup->scheduled_date)
                                    · {{ $leasePickup->scheduled_date->toDateString() }} {{ $leasePickup->scheduled_window }}
                                @endif
                            @endif
                        </td>
                        <td style="white-space:nowrap;">
                            @if ($leasePickup->isOpen() && Gate::allows('deployments.edit'))
                                <form method="POST" action="{{ route('lease-pickups.picked-up', $leasePickup) }}" class="form-inline" style="display:inline-block;">
                                    @csrf
                                    <input type="date" name="picked_up_at" value="{{ $leasePickup->scheduled_date?->toDateString() ?: now()->toDateString() }}" class="form-control input-sm" required>
                                    <button type="submit" class="btn btn-xs btn-success">{{ trans('admin/deployments/general.lease_pickups_mark') }}</button>
                                </form>
                                <form method="POST" action="{{ route('lease-pickups.cancel', $leasePickup) }}" style="display:inline-block;">
                                    @csrf
                                    <button type="submit" class="btn btn-xs btn-link">{{ trans('admin/deployments/general.lease_pickups_cancel') }}</button>
                                </form>
                            @endif
                            <a href="{{ route('lease-pickups.csv', $leasePickup) }}" class="btn btn-xs btn-default"><i class="fas fa-download"></i> {{ trans('admin/deployments/general.pickup_csv') }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- Pickups register: the way back through what actually left. --}}
<div class="box box-default decom-card">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.decom_pickups_title') }}
            <span class="text-muted" style="font-weight:normal; font-size:12px; margin-left:8px;">{{ trans('admin/deployments/general.decom_pickups_hint') }}</span>
        </h3>
    </div>
    <div class="box-body table-responsive no-padding">
        @if (count($decommission['pickups']) === 0)
            <p class="text-muted" style="margin:10px;">{{ trans('admin/deployments/general.decom_no_pickups') }}</p>
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
