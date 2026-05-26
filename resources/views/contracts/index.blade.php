@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/contracts/general.contracts') }}
    @parent
@stop

@section('header_right')
    @can('create', App\Models\Contract::class)
        <a href="{{ route('contracts.create') }}" class="btn btn-primary pull-right">
            {{ trans('general.create') }}
        </a>
    @endcan
@stop

{{-- Page content --}}
@section('content')
    @php
        $isActive       = request('is_active');
        $umbrellasOnly  = filter_var(request('umbrellas_only'), FILTER_VALIDATE_BOOLEAN);
        $selectedSource = request('source');
        $expiringDays   = request('expiring_within_days');
        $selectedTheme  = request('theme');

        $apiParams = array_filter([
            'is_active'            => $isActive,
            'umbrellas_only'       => $umbrellasOnly ? 'true' : null,
            'source'               => $selectedSource,
            'expiring_within_days' => $expiringDays,
            'theme'                => $selectedTheme,
        ], fn ($v) => $v !== null && $v !== '');

        $anyFilter = ! empty($apiParams);
    @endphp

    <x-container>

        {{-- KPI tiles --}}
        <div class="row" style="margin-bottom: 10px;">
            <div class="col-md-2 col-sm-4 col-xs-6">
                <a href="{{ route('contracts.index') }}" class="small-box-link">
                    <div class="small-box {{ ! $anyFilter ? 'bg-blue' : 'bg-aqua' }}">
                        <div class="inner">
                            <h3 style="font-size: 22px">{{ number_format($totalCount) }}</h3>
                            <p>{{ trans('admin/contracts/general.tile_all') }}</p>
                        </div>
                        <div class="icon"><i class="fas fa-file-contract" aria-hidden="true"></i></div>
                    </div>
                </a>
            </div>
            <div class="col-md-2 col-sm-4 col-xs-6">
                <a href="{{ route('contracts.index', ['is_active' => 'true']) }}" class="small-box-link">
                    <div class="small-box {{ $isActive === 'true' ? 'bg-blue' : 'bg-aqua' }}">
                        <div class="inner">
                            <h3 style="font-size: 22px">{{ number_format($activeCount) }}</h3>
                            <p>{{ trans('admin/contracts/general.tile_active') }}</p>
                        </div>
                        <div class="icon"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
                    </div>
                </a>
            </div>
            <div class="col-md-2 col-sm-4 col-xs-6">
                <a href="{{ route('contracts.index', ['umbrellas_only' => 'true']) }}" class="small-box-link">
                    <div class="small-box {{ $umbrellasOnly ? 'bg-blue' : 'bg-navy' }}">
                        <div class="inner">
                            <h3 style="font-size: 22px">{{ number_format($umbrellaCount) }}</h3>
                            <p>{{ trans('admin/contracts/general.tile_umbrellas') }}</p>
                        </div>
                        <div class="icon"><i class="fas fa-sitemap" aria-hidden="true"></i></div>
                    </div>
                </a>
            </div>
            <div class="col-md-2 col-sm-4 col-xs-6">
                <a href="{{ route('contracts.index', ['expiring_within_days' => 30]) }}" class="small-box-link">
                    <div class="small-box {{ (string) $expiringDays === '30' ? 'bg-blue' : ($expiring30 > 0 ? 'bg-red' : 'bg-green') }}">
                        <div class="inner">
                            <h3 style="font-size: 22px">{{ number_format($expiring30) }}</h3>
                            <p>{{ trans('admin/contracts/general.tile_expiring_30') }}</p>
                        </div>
                        <div class="icon"><i class="fas fa-hourglass-end" aria-hidden="true"></i></div>
                    </div>
                </a>
            </div>
            <div class="col-md-2 col-sm-4 col-xs-6">
                <a href="{{ route('contracts.index', ['expiring_within_days' => 90]) }}" class="small-box-link">
                    <div class="small-box {{ (string) $expiringDays === '90' ? 'bg-blue' : ($expiring90 > 0 ? 'bg-yellow' : 'bg-aqua') }}">
                        <div class="inner">
                            <h3 style="font-size: 22px">{{ number_format($expiring90) }}</h3>
                            <p>{{ trans('admin/contracts/general.tile_expiring_90') }}</p>
                        </div>
                        <div class="icon"><i class="fas fa-calendar-alt" aria-hidden="true"></i></div>
                    </div>
                </a>
            </div>
            <div class="col-md-2 col-sm-4 col-xs-6">
                <a href="{{ route('contracts.index', ['source' => 'synthesized']) }}" class="small-box-link">
                    <div class="small-box {{ $selectedSource === 'synthesized' ? 'bg-blue' : 'bg-purple' }}">
                        <div class="inner">
                            <h3 style="font-size: 22px">{{ number_format($synthesizedCount) }}</h3>
                            <p>{{ trans('admin/contracts/general.tile_synthesized') }}</p>
                        </div>
                        <div class="icon"><i class="fas fa-cogs" aria-hidden="true"></i></div>
                    </div>
                </a>
            </div>
        </div>

        @if ($themes->isNotEmpty())
            <div class="row" style="margin-bottom: 10px;">
                <div class="col-md-12">
                    <span style="margin-right: 8px; color: #666;">{{ trans('admin/contracts/general.filter_by_theme') }}:</span>
                    @foreach ($themes as $theme)
                        <a href="{{ route('contracts.index', ['theme' => $theme->theme]) }}"
                           class="btn btn-xs {{ $selectedTheme === $theme->theme ? 'btn-primary' : 'btn-default' }}"
                           style="margin-right: 4px; margin-bottom: 4px;">
                            {{ $theme->theme }} <span class="badge">{{ $theme->n }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <x-box>
            <x-table.contracts
                fixed_right_number="1"
                fixed_number="1"
                show_advanced_search="true"
                name="contracts"
                :route="route('api.contracts.index', $apiParams)"
            />
        </x-box>
    </x-container>
@stop

@section('moar_scripts')
    @include('partials.bootstrap-table')
@stop
