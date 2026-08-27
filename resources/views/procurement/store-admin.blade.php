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

@php($missingParts = $items->filter(fn ($item) => ! $item->mfr_part_number || ! $item->vendor_sku)->count())
@if ($missingParts)
    <div class="callout callout-warning" style="margin-bottom:14px;">
        {{ trans_choice('admin/store/general.parts_missing_warning', $missingParts, ['count' => $missingParts]) }}
    </div>
@endif

{{-- The store's own pills, in the store's own order, so filtering here
     feels like filtering there. Purely client-side: the page already holds
     every row, and a filter that round-tripped would lose the edits in the
     row forms. --}}
<div class="st-pills" id="sa-pills">
    <button type="button" class="st-pill active" data-cat="">{{ trans('admin/store/general.all_categories') }}</button>
    @foreach ($categories as $category)
        <button type="button" class="st-pill" data-cat="{{ $category }}">{{ $category }}</button>
    @endforeach
</div>

<style>
.st-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
.st-pill { border: 1px solid light-dark(#d2d2d7, #4a4a4f); background: transparent; border-radius: 980px;
           padding: 6px 16px; font-size: 13px; color: inherit; cursor: pointer; }
.st-pill.active { background: light-dark(#1d1d1f, #f5f5f7); color: light-dark(#fff, #1d1d1f); border-color: transparent; }
/* A blank part number is not a neutral empty field — it is a row that
   cannot be ordered — so it reads as a problem at a glance instead of
   only when someone tries to send it. */
.st-part-missing { border-color: #d9534f; background: light-dark(#fdf3f3, #3a2626); }
</style>

<div class="box box-default">
    <div class="box-body table-responsive">
        <table class="table table-striped table-condensed">
            <thead>
                <tr>
                    <th>{{ trans('admin/store/general.store_image') }}</th>
                    <th>{{ trans('general.category') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.builder_col_description') }}</th>
                    <th>{{ trans('mail.store_vendor_csv_mfr') }}</th>
                    <th>{{ trans('mail.store_vendor_csv_edc') }}</th>
                    <th>{{ trans('mail.store_vendor_csv_warranty') }}</th>
                    <th>{{ trans('general.supplier') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.builder_col_unit_cost') }}</th>
                    <th>{{ trans('general.asset_model') }}</th>
                    <th>{{ trans('admin/store/general.show_in_store') }}</th>
                    <th>{{ trans('admin/store/general.store_sort') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $item)
                    <tr data-category="{{ $item->category }}">
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
                        {{-- The two part numbers are the fields an order
                             actually travels on: CDW identifies a product by
                             the manufacturer's number and places their own
                             (the EDC). A row missing either cannot be ordered
                             at all, so both are editable in place here and a
                             blank one is called out rather than left as an
                             empty cell nobody notices. --}}
                        <td style="min-width:150px;">
                            <input type="text" name="mfr_part_number" value="{{ $item->mfr_part_number }}" form="sa-{{ $item->id }}"
                                   class="form-control input-sm {{ $item->mfr_part_number ? '' : 'st-part-missing' }}"
                                   placeholder="{{ trans('admin/store/general.part_missing') }}"
                                   style="font-family:ui-monospace,Menlo,monospace; font-size:12px;">
                        </td>
                        <td style="min-width:110px;">
                            <input type="text" name="vendor_sku" value="{{ $item->vendor_sku }}" form="sa-{{ $item->id }}"
                                   class="form-control input-sm {{ $item->vendor_sku ? '' : 'st-part-missing' }}"
                                   placeholder="{{ trans('admin/store/general.part_missing') }}"
                                   style="font-family:ui-monospace,Menlo,monospace; font-size:12px;">
                        </td>
                        <td style="width:120px;">
                            <select name="warranty_months" form="sa-{{ $item->id }}" class="form-control input-sm">
                                <option value="">{{ trans('general.na') }}</option>
                                @foreach ([12, 24, 36, 48, 60] as $months)
                                    <option value="{{ $months }}" @selected((int) $item->warranty_months === $months)>
                                        {{ trans_choice('admin/store/general.warranty_years', intdiv($months, 12), ['count' => intdiv($months, 12)]) }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        {{-- The supplier decides who the order request is
                             addressed to, so a blank one is as blocking as a
                             missing part number. --}}
                        <td style="min-width:170px;">
                            @unless ($item->supplier)
                                <span class="label label-danger">{{ trans('admin/store/general.no_supplier') }}</span>
                            @endunless
                            <select class="js-data-ajax" data-endpoint="suppliers" name="supplier_id" form="sa-{{ $item->id }}"
                                    data-placeholder="{{ trans('general.select_supplier') }}" style="width:100%;">
                                @if ($item->supplier)
                                    <option value="{{ $item->supplier_id }}" selected>{{ $item->supplier->name }}</option>
                                @endif
                            </select>
                        </td>
                        <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($item->effectiveCost()) }}</td>
                        <td style="min-width:180px;">
                            @unless ($item->model)
                                <span class="label label-danger">{{ trans('admin/store/general.no_model') }}</span>
                            @endunless
                            <select class="js-data-ajax" data-endpoint="models" name="model_id" form="sa-{{ $item->id }}"
                                    data-placeholder="{{ trans('general.select_model') }}" style="width:100%;">
                                @if ($item->model)
                                    <option value="{{ $item->model_id }}" selected>{{ $item->model->name }}</option>
                                @endif
                            </select>
                        </td>
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

<script nonce="{{ csrf_token() }}">
(function () {
    var pills = document.getElementById('sa-pills');
    if (! pills) { return; }
    var rows = document.querySelectorAll('tr[data-category]');

    function apply(category) {
        Array.prototype.forEach.call(pills.querySelectorAll('.st-pill'), function (pill) {
            pill.classList.toggle('active', pill.getAttribute('data-cat') === category);
        });
        Array.prototype.forEach.call(rows, function (row) {
            row.style.display = (category === '' || row.getAttribute('data-category') === category) ? '' : 'none';
        });
        var url = new URL(window.location.href);
        if (category === '') { url.searchParams.delete('category'); } else { url.searchParams.set('category', category); }
        window.history.replaceState(null, '', url.toString());
    }

    pills.addEventListener('click', function (e) {
        var pill = e.target.closest('.st-pill');
        if (pill) { apply(pill.getAttribute('data-cat')); }
    });

    // Deep links come from the store — /store?category=Tablets — so the
    // same parameter lands here on the same shelf.
    var initial = new URL(window.location.href).searchParams.get('category') || '';
    if (initial && pills.querySelector('.st-pill[data-cat="' + initial.replace(/"/g, '') + '"]')) { apply(initial); }
})();
</script>
@stop
