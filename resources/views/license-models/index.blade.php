@extends('layouts/default')

@section('title')
    {{ trans('admin/licensemodels/general.license_models') }}
    @parent
@stop

@section('header_right')
    @can('create', \App\Models\LicenseModel::class)
        <a href="{{ route('license-models.create') }}" class="btn btn-primary pull-right">
            {{ trans('general.create') }}
        </a>
    @endcan
@stop

@section('content')
    <x-container>
        <x-box>

            <div class="callout callout-info">
                <p>{{ trans('admin/licensemodels/general.about_help') }}</p>
            </div>

            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>{{ trans('general.icon') }}</th>
                        <th>{{ trans('general.name') }}</th>
                        <th>{{ trans('admin/licensemodels/general.type_code') }}</th>
                        <th style="text-align: center;">{{ trans('admin/licenses/form.seats') }}</th>
                        <th style="text-align: center;">{{ trans('admin/licenses/form.license_key') }}</th>
                        <th style="text-align: center;">{{ trans('general.checkout') }}</th>
                        <th style="text-align: center;">{{ trans('admin/licenses/form.expiration') }}</th>
                        <th style="text-align: center;">{{ trans('admin/licensemodels/general.subscription') }}</th>
                        <th style="text-align: center;">{{ trans('admin/licensemodels/general.licenses_using') }}</th>
                        <th class="text-right">{{ trans('table.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (\App\Models\LicenseModel::orderBy('name')->withCount('licenses')->get() as $m)
                        <tr>
                            <td>@if ($m->icon)<i class="fa-solid {{ $m->icon }}" aria-hidden="true"></i>@endif</td>
                            <td><a href="{{ route('license-models.show', $m) }}">{{ $m->name }}</a></td>
                            <td><code>{{ $m->type_code }}</code></td>
                            <td style="text-align: center;">@if ($m->has_seats)<i class="fas fa-check text-success"></i>@else<span class="text-muted">—</span>@endif</td>
                            <td style="text-align: center;">@if ($m->has_product_key)<i class="fas fa-check text-success"></i>@else<span class="text-muted">—</span>@endif</td>
                            <td style="text-align: center;">@if ($m->has_checkout)<i class="fas fa-check text-success"></i>@else<span class="text-muted">—</span>@endif</td>
                            <td style="text-align: center;">@if ($m->has_expiration)<i class="fas fa-check text-success"></i>@else<span class="text-muted">—</span>@endif</td>
                            <td style="text-align: center;">@if ($m->is_subscription)<i class="fas fa-check text-success"></i>@else<span class="text-muted">—</span>@endif</td>
                            <td style="text-align: center;">{{ $m->licenses_count }}</td>
                            <td class="text-right">
                                @can('update', \App\Models\LicenseModel::class)
                                    <a href="{{ route('license-models.edit', $m) }}" class="btn btn-warning btn-sm" data-tooltip="true" title="{{ trans('button.edit') }}"><x-icon type="edit"/></a>
                                @endcan
                                @can('delete', \App\Models\LicenseModel::class)
                                    <form action="{{ route('license-models.destroy', $m) }}" method="POST" style="display: inline;" onsubmit="return confirm('{{ trans('admin/licensemodels/message.delete.confirm') }}');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm" data-tooltip="true" title="{{ trans('button.delete') }}" {{ $m->licenses_count > 0 ? 'disabled' : '' }}>
                                            <x-icon type="delete"/>
                                        </button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-muted text-center">{{ trans('admin/licensemodels/general.none_defined') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-box>
    </x-container>
@stop
