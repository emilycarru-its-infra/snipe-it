@extends('layouts/default')

@section('title')
    {{ $item->name }}
    @parent
@stop

@section('header_right')
    @can('update', \App\Models\LicenseModel::class)
        <a href="{{ route('license-models.edit', $item) }}" class="btn btn-primary pull-right">{{ trans('general.edit') }}</a>
    @endcan
@stop

@section('content')
    <x-container columns="2">
        <x-page-column class="col-md-9 main-panel">
            <x-box>
                <h2 style="margin-top: 0;">
                    @if ($item->icon)<i class="fa-solid {{ $item->icon }}" aria-hidden="true"></i>@endif
                    {{ $item->name }}
                </h2>
                <p class="text-muted"><code>{{ $item->type_code }}</code></p>
                @if ($item->description)
                    <p>{{ $item->description }}</p>
                @endif

                <h4>{{ trans('admin/licensemodels/general.behavior_flags') }}</h4>
                <table class="table table-striped">
                    <tbody>
                        @foreach ([
                            'has_seats' => 'flag_has_seats',
                            'has_product_key' => 'flag_has_product_key',
                            'has_checkout' => 'flag_has_checkout',
                            'has_expiration' => 'flag_has_expiration',
                            'has_user_email' => 'flag_has_user_email',
                            'has_reassignable' => 'flag_has_reassignable',
                            'is_subscription' => 'flag_is_subscription',
                        ] as $f => $label_key)
                            <tr>
                                <th>{{ trans('admin/licensemodels/general.'.$label_key) }}</th>
                                <td>
                                    @if ($item->{$f})
                                        <i class="fas fa-check text-success"></i> {{ trans('general.yes') }}
                                    @else
                                        <i class="fas fa-times text-muted"></i> {{ trans('general.no') }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        <tr>
                            <th>{{ trans('admin/licensemodels/general.default_seats') }}</th>
                            <td>{{ $item->default_seats }}</td>
                        </tr>
                        <tr>
                            <th>{{ trans('admin/licensemodels/general.flag_default_reassignable') }}</th>
                            <td>
                                @if ($item->default_reassignable)
                                    <i class="fas fa-check text-success"></i> {{ trans('general.yes') }}
                                @else
                                    <i class="fas fa-times text-muted"></i> {{ trans('general.no') }}
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>{{ trans('admin/licensemodels/general.licenses_using') }}</th>
                            <td>{{ $item->licenses()->count() }}</td>
                        </tr>
                    </tbody>
                </table>
            </x-box>
        </x-page-column>
    </x-container>
@stop
