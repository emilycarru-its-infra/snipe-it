@extends('layouts/default')

@section('title')
    {{ $item->name }}
    @parent
@stop

@section('header_right')
    @can('update', \App\Models\ProductIdentity::class)
        <a href="{{ route('product-identities.edit', $item) }}" class="btn btn-primary pull-right">{{ trans('general.edit') }}</a>
    @endcan
@stop

@section('content')
    <x-container>
        <x-box>
            <h2 style="margin-top: 0;">{{ $item->name }}</h2>
            @if ($item->publisher)
                <p class="text-muted">{{ $item->publisher }}</p>
            @endif
            @if ($item->procurement_name)
                <p><strong>{{ trans('admin/productidentities/general.procurement_name') }}:</strong> {{ $item->procurement_name }}</p>
            @endif
            @if ($item->notes)
                <p>{!! nl2br(e($item->notes)) !!}</p>
            @endif

            <h4>{{ trans('admin/productidentities/general.aliases') }}</h4>
            @if ($item->aliases->isEmpty())
                <p class="text-warning">{{ trans('admin/productidentities/general.no_aliases') }}</p>
            @else
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('admin/productidentities/general.platform') }}</th>
                            <th>{{ trans('admin/productidentities/general.match_type') }}</th>
                            <th>{{ trans('admin/productidentities/general.pattern') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($item->aliases->sortBy(['platform', 'match_type', 'pattern']) as $alias)
                            <tr>
                                <td>{{ trans('admin/productidentities/general.platform_'.$alias->platform) }}</td>
                                <td>{{ trans('admin/productidentities/general.match_'.$alias->match_type) }}</td>
                                <td><code>{{ $alias->pattern }}</code></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <h4>{{ trans('admin/productidentities/general.license_links') }}</h4>
            @if ($item->licenses->isEmpty())
                <p class="text-warning">{{ trans('admin/productidentities/general.no_licenses') }}</p>
            @else
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('general.license') }}</th>
                            <th>{{ trans('admin/contracts/general.contract') }}</th>
                            <th>{{ trans('admin/productidentities/general.fiscal_year') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($item->licenses->sortBy('name') as $license)
                            <tr>
                                <td><a href="{{ route('licenses.show', $license) }}">{{ $license->name }}</a></td>
                                <td>{{ $license->contract?->name }}</td>
                                <td>{{ $license->pivot->fiscal_year ?? trans('admin/productidentities/general.every_year') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-box>
    </x-container>
@stop
