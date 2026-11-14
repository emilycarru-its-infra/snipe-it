@extends('layouts/default')

@section('title')
    {{ trans('admin/productidentities/general.product_identities') }}
    @parent
@stop

@section('header_right')
    @can('create', \App\Models\ProductIdentity::class)
        <a href="{{ route('product-identities.create') }}" class="btn btn-primary pull-right">
            {{ trans('general.create') }}
        </a>
    @endcan
@stop

@section('content')
    <x-container>
        <x-box>
            <div class="callout callout-info">
                <p>{{ trans('admin/productidentities/general.about_help') }}</p>
            </div>

            <form method="GET" action="{{ route('product-identities.index') }}" class="form-inline" style="margin-bottom: 15px;">
                <strong>{{ trans('admin/productidentities/general.probe_title') }}</strong>
                <input type="text" name="probe" class="form-control" value="{{ $probe['name'] ?? '' }}" placeholder="{{ trans('admin/productidentities/general.probe_name') }}" aria-label="{{ trans('admin/productidentities/general.probe_name') }}" maxlength="255">
                <select name="platform" class="form-control" aria-label="{{ trans('admin/productidentities/general.platform') }}">
                    @foreach (\App\Models\ProductIdentityAlias::PLATFORMS as $platform)
                        <option value="{{ $platform === 'any' ? '' : $platform }}" @selected(($probe['platform'] ?? null) === $platform)>{{ trans('admin/productidentities/general.platform_'.$platform) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-default">{{ trans('admin/productidentities/general.probe_button') }}</button>
                @if ($probe)
                    <span style="margin-left: 10px;">
                        @if ($probe['result'])
                            {{ trans('admin/productidentities/general.probe_resolves', ['name' => $probe['name']]) }}
                            <a href="{{ route('product-identities.show', $probe['result']) }}">{{ $probe['result']->name }}</a>
                        @else
                            <span class="text-warning">{{ trans('admin/productidentities/general.probe_unresolved', ['name' => $probe['name']]) }}</span>
                        @endif
                    </span>
                @endif
            </form>

            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>{{ trans('general.name') }}</th>
                        <th>{{ trans('admin/productidentities/general.publisher') }}</th>
                        <th>{{ trans('admin/productidentities/general.procurement_name') }}</th>
                        <th style="text-align: center;">{{ trans('admin/productidentities/general.alias_count') }}</th>
                        <th style="text-align: center;">{{ trans('admin/productidentities/general.license_count') }}</th>
                        <th class="text-right">{{ trans('table.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $p)
                        <tr>
                            <td><a href="{{ route('product-identities.show', $p) }}">{{ $p->name }}</a></td>
                            <td>{{ $p->publisher }}</td>
                            <td>{{ $p->procurement_name }}</td>
                            <td style="text-align: center;">{{ $p->aliases_count }}</td>
                            <td style="text-align: center;">{{ $p->licenses_count }}</td>
                            <td class="text-right">
                                @can('update', \App\Models\ProductIdentity::class)
                                    <a href="{{ route('product-identities.edit', $p) }}" class="btn btn-warning btn-sm" data-tooltip="true" title="{{ trans('button.edit') }}"><x-icon type="edit"/></a>
                                @endcan
                                @can('delete', \App\Models\ProductIdentity::class)
                                    <form action="{{ route('product-identities.destroy', $p) }}" method="POST" style="display: inline;" onsubmit="return confirm('{{ trans('admin/productidentities/message.delete.confirm') }}');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm" data-tooltip="true" title="{{ trans('button.delete') }}"><x-icon type="delete"/></button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted text-center">{{ trans('admin/productidentities/general.none_defined') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-box>

        @if ($unlinkedCount > 0)
            <x-box>
                <h4 style="margin-top: 0;">{{ trans('admin/productidentities/general.unlinked_title') }}</h4>
                <p class="text-muted">
                    {{ trans('admin/productidentities/general.unlinked_help', ['count' => $unlinkedCount]) }}
                    @if ($unlinkedCount > $unlinked->count())
                        {{ trans('admin/productidentities/general.unlinked_more', ['shown' => $unlinked->count(), 'count' => $unlinkedCount]) }}
                    @endif
                </p>
                <table class="table table-striped table-condensed">
                    <thead>
                        <tr>
                            <th>{{ trans('general.name') }}</th>
                            <th>{{ trans('admin/productidentities/general.license_model') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($unlinked as $license)
                            <tr>
                                <td><a href="{{ route('licenses.show', $license) }}">{{ $license->name }}</a></td>
                                <td>{{ $license->licenseModel?->name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-box>
        @endif
    </x-container>
@stop
