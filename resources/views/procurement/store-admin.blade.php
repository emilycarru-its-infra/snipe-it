@extends('layouts/default')

@section('title')
    {{ trans('admin/store/general.store_admin') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('store.index') }}" class="btn btn-sm btn-default">{{ trans('admin/store/general.go_store') }}</a>
    <a href="{{ route('procurement.index') }}" class="btn btn-sm btn-default">{{ trans('admin/store/general.procurement') }}</a>
@stop

@section('content')

<p class="text-muted">{{ trans('admin/store/general.store_admin_intro') }}</p>

<div class="box box-default">
    <div class="box-body table-responsive">
        <table class="table table-striped table-condensed">
            <thead>
                <tr>
                    <th>{{ trans('admin/store/general.store_image') }}</th>
                    <th>{{ trans('general.category') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.builder_col_description') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.builder_col_unit_cost') }}</th>
                    <th>{{ trans('admin/store/general.show_in_store') }}</th>
                    <th>{{ trans('admin/store/general.store_sort') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $item)
                    <tr>
                        <td style="width:64px;">
                            @if ($item->storeImageUrl())
                                <img src="{{ $item->storeImageUrl() }}" alt="" style="max-height:40px; max-width:56px; object-fit:contain;">
                            @else
                                <span class="text-muted" style="font-size:11px;">{{ trans('admin/store/general.no_image') }}</span>
                            @endif
                        </td>
                        <td>{{ $item->category ?: trans('general.na') }}</td>
                        <td>
                            {{ $item->name }}
                            @if ($item->isEstimate())
                                <span class="label label-warning">{{ trans('admin/purchase-orders/general.builder_estimate_badge') }}</span>
                            @endif
                        </td>
                        <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($item->effectiveCost()) }}</td>
                        <td>
                            <input type="checkbox" name="show_in_store" value="1" form="sa-{{ $item->id }}"
                                   {{ $item->show_in_store ? 'checked' : '' }}>
                        </td>
                        <td style="width:90px;">
                            <input type="number" name="store_sort" value="{{ $item->store_sort }}" form="sa-{{ $item->id }}"
                                   class="form-control input-sm" style="width:70px;">
                        </td>
                        <td style="white-space:nowrap;">
                            <input type="file" name="image" accept="image/*" form="sa-{{ $item->id }}" style="display:inline-block; font-size:11px;">
                            <button type="submit" form="sa-{{ $item->id }}" class="btn btn-sm btn-primary">{{ trans('general.save') }}</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- One form per row, kept out of the table so rows never nest forms. --}}
@foreach ($items as $item)
    <form method="POST" action="{{ route('procurement.store-admin.update', $item->id) }}"
          id="sa-{{ $item->id }}" enctype="multipart/form-data">
        {{ csrf_field() }}
    </form>
@endforeach
@stop
