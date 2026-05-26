@extends('layouts/default')

{{-- Page title --}}
@section('title')
{{ trans('admin/licenses/general.software_licenses') }}
@parent
@stop


{{-- Page content --}}
@section('content')
    @php
        $selectedModelId  = request('license_model_id');
        $selectedExpiring = request('expiring_within_days');
        $apiParams = array_filter([
            'status'               => request('status'),
            'license_model_id'     => $selectedModelId,
            'expiring_within_days' => $selectedExpiring,
        ], fn ($v) => $v !== null && $v !== '');
    @endphp

    <x-container>

        {{-- Type filter tiles --}}
        @if (($tiles ?? collect())->isNotEmpty() || ($totalCount ?? 0) > 0)
            <div class="row" style="margin-bottom: 10px;">
                <div class="col-md-2 col-sm-4 col-xs-6">
                    <a href="{{ route('licenses.index') }}" class="small-box-link">
                        <div class="small-box {{ ! $selectedModelId && ! $selectedExpiring ? 'bg-blue' : 'bg-aqua' }}">
                            <div class="inner">
                                <h3 style="font-size: 22px">{{ number_format($totalCount) }}</h3>
                                <p>{{ trans('admin/licenses/general.tile_all') }}</p>
                            </div>
                            <div class="icon"><i class="fas fa-list" aria-hidden="true"></i></div>
                        </div>
                    </a>
                </div>
                @foreach ($tiles as $tile)
                    <div class="col-md-2 col-sm-4 col-xs-6">
                        <a href="{{ route('licenses.index', ['license_model_id' => $tile['id']]) }}" class="small-box-link">
                            <div class="small-box {{ (string) $selectedModelId === (string) $tile['id'] ? 'bg-blue' : 'bg-aqua' }}">
                                <div class="inner">
                                    <h3 style="font-size: 22px">{{ number_format($tile['count']) }}</h3>
                                    <p>{{ $tile['name'] }}</p>
                                </div>
                                <div class="icon"><i class="fas {{ $tile['icon'] }}" aria-hidden="true"></i></div>
                            </div>
                        </a>
                    </div>
                @endforeach
                <div class="col-md-2 col-sm-4 col-xs-6">
                    <a href="{{ route('licenses.index', ['expiring_within_days' => 90]) }}" class="small-box-link">
                        <div class="small-box {{ $selectedExpiring ? 'bg-red' : ($expiring90 > 0 ? 'bg-yellow' : 'bg-green') }}">
                            <div class="inner">
                                <h3 style="font-size: 22px">{{ number_format($expiring90) }}</h3>
                                <p>{{ trans('admin/licenses/general.tile_expiring_90') }}</p>
                            </div>
                            <div class="icon"><i class="fas fa-hourglass-end" aria-hidden="true"></i></div>
                        </div>
                    </a>
                </div>
                <div class="col-md-2 col-sm-4 col-xs-6">
                    <div class="small-box bg-navy">
                        <div class="inner">
                            <h3 style="font-size: 22px">{{ number_format($totalSeats) }}</h3>
                            <p>{{ trans('admin/licenses/general.tile_total_seats') }}</p>
                        </div>
                        <div class="icon"><i class="fas fa-chair" aria-hidden="true"></i></div>
                    </div>
                </div>
            </div>
        @endif

        <x-box>

            <x-slot:bulkactions>
                <x-table.bulk-actions
                    name='licenses'
                    action_route="{{ route('licenses.bulk.delete') }}"
                    model_name="license"
                >
                    @can('delete', App\Models\License::class)
                        <option value="delete">{{ trans('general.delete') }}</option>
                    @endcan
                </x-table.bulk-actions>
            </x-slot:bulkactions>

            <x-table.licenses
                fixed_right_number="2"
                fixed_number="1"
                show_footer="true"
                show_advanced_search="true"
                name="licenses"
                :route="route('api.licenses.index', $apiParams)"/>

        </x-box>
    </x-container>
@stop

@section('moar_scripts')
@include ('partials.bootstrap-table')

@stop
