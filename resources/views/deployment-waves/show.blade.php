@extends('layouts/default')

@section('title')
    {{ $wave->name }} @parent
@stop

@section('header_right')
    <a href="{{ route('deployments.forecast', ['fiscal_year' => $wave->fiscal_year]) }}" class="btn btn-sm btn-default"><i class="fas fa-calendar-alt"></i> {{ trans('admin/deployments/general.add_from_forecast') }}</a>
    <a href="{{ route('deployments.storage') }}" class="btn btn-sm btn-default"><i class="fas fa-boxes"></i> {{ trans('admin/deployments/general.storage_title') }}</a>
    <a href="{{ route('deployment-waves.export', $wave) }}" class="btn btn-sm btn-default"><i class="fas fa-download"></i> {{ trans('admin/deployments/general.download') }}</a>
    @include('deployment-waves._announce')
@stop

@section('content')

{{-- Wave meta header. Everything a person types lives here and edits in
     place — the dedicated Update page is gone. Cells flow into columns so
     the row count stays short and the right half of a wide screen is no
     longer blank; related fields share a cell (the two window dates, the
     two locations, type with its color). --}}
@php
    $waveLocations = \App\Models\Location::orderBy('name')->pluck('name', 'id');
    $waveTypes = \App\Models\DeploymentType::active()->ordered()->pluck('name', 'id');
    $wavePos = \App\Models\PurchaseOrder::orderByDesc('id')->limit(500)->get()
        ->mapWithKeys(fn ($po) => [$po->id => $po->po_number ?? ('#'.$po->id)]);
    $waveStates = collect(\App\Models\DeploymentWave::STATES)->mapWithKeys(fn ($s) => [$s => ucfirst($s)]);
@endphp
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title wave-meta-cell" style="display:inline-flex;">
            <span class="label" style="background-color: {{ $wave->displayColor() }}; color:#fff;">{{ $wave->typeLabel() }}</span>
            @include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'name'])
        </h3>
        <div class="box-tools pull-right">
            <span class="label label-default">{{ ucfirst($wave->wave_state) }}</span>
        </div>
    </div>
    <div class="box-body">
        <div class="wave-meta-grid">
            <div class="wave-meta-cell">
                <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                <div>
                    <div class="wave-meta-label">{{ trans('admin/deployments/general.fiscal_year') }}</div>
                    @include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'fiscal_year'])
                </div>
            </div>
            <div class="wave-meta-cell">
                <i class="fas fa-layer-group" aria-hidden="true"></i>
                <div>
                    <div class="wave-meta-label">{{ trans('admin/deployments/general.deployment_type') }} · {{ trans('admin/deployments/general.color') }}</div>
                    @include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'deployment_type_id', 'element' => 'select', 'options' => $waveTypes, 'display' => $wave->type?->name])
                    <span class="wave-color-dot" style="background: {{ $wave->displayColor() }};"></span>@include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'color', 'element' => 'color', 'display' => $wave->color])
                </div>
            </div>
            <div class="wave-meta-cell">
                <i class="fas fa-flag" aria-hidden="true"></i>
                <div>
                    <div class="wave-meta-label">{{ trans('admin/deployments/general.wave_state') }}</div>
                    @include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'wave_state', 'element' => 'select', 'options' => $waveStates, 'display' => ucfirst($wave->wave_state)])
                </div>
            </div>
            <div class="wave-meta-cell">
                <i class="fas fa-truck" aria-hidden="true"></i>
                <div>
                    <div class="wave-meta-label">{{ trans('admin/deployments/general.arrival_window') }}</div>
                    @include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'arrival_window_start', 'element' => 'date'])
                    <span class="text-muted">–</span>
                    @include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'arrival_window_end', 'element' => 'date'])
                </div>
            </div>
            <div class="wave-meta-cell">
                <i class="fas fa-calendar-check" aria-hidden="true"></i>
                <div>
                    <div class="wave-meta-label">{{ trans('admin/deployments/general.deploy_window') }}</div>
                    @include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'target_start_date', 'element' => 'date'])
                    <span class="text-muted">–</span>
                    @include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'target_end_date', 'element' => 'date'])
                </div>
            </div>
            <div class="wave-meta-cell">
                <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                <div>
                    <div class="wave-meta-label">{{ trans('admin/deployments/general.location') }}</div>
                    @include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'location_id', 'element' => 'select', 'options' => $waveLocations, 'display' => $wave->location?->name])
                    <div class="wave-meta-label" style="margin-top:6px;">{{ trans('admin/deployments/general.storage_location') }}</div>
                    @include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'storage_location_id', 'element' => 'select', 'options' => $waveLocations, 'display' => $wave->storageLocation?->name])
                </div>
            </div>
            <div class="wave-meta-cell">
                <i class="fas fa-user" aria-hidden="true"></i>
                <div>
                    <div class="wave-meta-label">{{ trans('admin/deployments/general.owner') }}</div>
                    @include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'owner_id', 'element' => 'user', 'selectedLabel' => $wave->owner?->full_name, 'display' => $wave->owner?->full_name])
                </div>
            </div>
            <div class="wave-meta-cell">
                <i class="fas fa-file-invoice-dollar" aria-hidden="true"></i>
                <div>
                    <div class="wave-meta-label">{{ trans('admin/deployments/general.purchase_order') }}</div>
                    @include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'purchase_order_id', 'element' => 'select', 'options' => $wavePos, 'display' => $wave->purchaseOrder?->po_number])
                    @if ($wave->purchaseOrder)
                        <a href="{{ route('purchase-orders.show', $wave->purchaseOrder) }}" class="wave-meta-follow" title="{{ trans('general.view') }}"><i class="fas fa-external-link-alt" aria-hidden="true"></i></a>
                    @endif
                </div>
            </div>
            @if ($wave->announced_at)
                <div class="wave-meta-cell">
                    <i class="fas fa-bullhorn" aria-hidden="true"></i>
                    <div>
                        <div class="wave-meta-label">{{ trans('admin/deployments/general.announced_at') }}</div>
                        <span class="wave-inline-value">{{ \App\Helpers\Helper::getFormattedDateObject($wave->announced_at, 'datetime', false) }}</span>
                    </div>
                </div>
            @endif
            <div class="wave-meta-cell wave-meta-notes">
                <i class="fas fa-sticky-note" aria-hidden="true"></i>
                <div style="min-width:0;">
                    <div class="wave-meta-label">{{ trans('admin/deployments/general.notes') }}</div>
                    @include('deployment-waves._inline-field', ['wave' => $wave, 'field' => 'notes', 'element' => 'textarea'])
                </div>
            </div>
        </div>

        <style>
            .wave-meta-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
                gap: 16px 24px;
            }
            .wave-meta-cell { display: flex; gap: 10px; align-items: flex-start; min-width: 0; }
            .wave-meta-cell > i { color: var(--color-fg-muted, #999); margin-top: 3px; width: 16px; text-align: center; flex: 0 0 auto; }
            .wave-meta-label { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--color-fg-muted, #999); margin-bottom: 2px; }
            .wave-meta-notes { grid-column: 1 / -1; }
            .wave-color-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; vertical-align: baseline; margin: 0 2px 0 10px; }
            .wave-meta-follow { margin-left: 6px; font-size: 12px; }
            .wave-inline-value { overflow-wrap: anywhere; }
            /* Pencils hide until their cell is hovered — same affordance as the
               asset page's grouped detail cards. */
            .wave-inline-pencil, .wave-inline-pencil i { color: #bbb !important; }
            .wave-inline-pencil { margin-left: 6px; font-size: 12px; opacity: 0; transition: opacity .12s ease; }
            .wave-meta-cell:hover .wave-inline-pencil { opacity: .6; }
            .wave-meta-cell:hover .wave-inline-pencil:hover { opacity: 1; }
            .wave-meta-cell:hover .wave-inline-pencil:hover i { color: #777 !important; }
            .js-inline-edit-form { margin-top: 4px; }
            .js-inline-edit-form .select2-container { min-width: 220px; }
        </style>
    </div>
</div>

{{-- Arrivals rollup (P2b) --}}
@if ($arrivals['linked'] > 0)
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title"><i class="fas fa-truck"></i> {{ trans('admin/deployments/general.arrivals_title') }}</h3>
        <div class="box-tools pull-right">
            <span class="label label-primary">{{ trans('admin/deployments/general.arrivals_summary', ['received' => $arrivals['received'], 'linked' => $arrivals['linked'], 'in_transit' => $arrivals['in_transit']]) }}</span>
        </div>
    </div>
    <div class="box-body">
        <div class="row">
            <div class="col-sm-3 text-center">
                <span class="label label-success">{{ trans('admin/deployments/general.arrivals_received') }}</span>
                <h3 style="margin:6px 0 0;">{{ $arrivals['received'] }}</h3>
            </div>
            <div class="col-sm-3 text-center">
                <span class="label label-warning">{{ trans('admin/deployments/general.arrivals_in_transit') }}</span>
                <h3 style="margin:6px 0 0;">{{ $arrivals['in_transit'] }}</h3>
            </div>
            <div class="col-sm-3 text-center">
                <span class="label label-default">{{ trans('admin/deployments/general.arrivals_not_ordered') }}</span>
                <h3 style="margin:6px 0 0;">{{ $arrivals['not_ordered'] }}</h3>
            </div>
            <div class="col-sm-3">
                @if (count($arrivals['trackers']))
                    <strong>{{ trans('admin/deployments/general.arrivals_tracking') }}</strong>
                    <ul class="list-unstyled" style="margin-bottom:0;">
                        @foreach ($arrivals['trackers'] as $t)
                            <li><i class="fas fa-barcode text-muted"></i> {{ $t['tracking'] ?: '—' }}@if ($t['carrier']) <span class="text-muted">({{ $t['carrier'] }})</span>@endif</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
@endif

@include('deployment-waves._items')

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

@stop
