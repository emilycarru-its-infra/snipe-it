@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ $reportTitle }}
    @parent
@stop

{{-- Page-header actions --}}
@section('header_right')
    <a href="{{ route('requisitions.index') }}" class="btn btn-sm btn-default">
        {{ trans('admin/purchase-orders/general.requisitions') }}
    </a>
@stop

{{-- Page content --}}
@section('content')

{{-- The catalog is handed to the page as JSON rather than rendered as rows:
     filtering, adding and re-totalling all happen client-side so picking a
     dozen configurations doesn't cost a dozen round trips. Only the finished
     basket is POSTed. --}}
<script type="application/json" id="pob-catalog">@json($catalog)</script>
<script type="application/json" id="pob-basket">@json($basket)</script>

<form method="POST" action="{{ route('requisitions.store') }}" id="pob-form">
    {{ csrf_field() }}
    @if ($requisition)
        <input type="hidden" name="requisition_id" value="{{ $requisition->id }}">
    @endif

    <div class="row">
        <div class="col-md-12">
            <p class="text-muted">{{ trans('admin/purchase-orders/general.builder_intro') }}</p>
        </div>
    </div>

    <div class="row">
        {{-- Purchase order header --}}
        <div class="col-md-12">
            <div class="box box-default">
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-5 form-group">
                            <label for="pob-title">{{ trans('admin/purchase-orders/general.builder_title') }}</label>
                            <input type="text" name="title" id="pob-title" class="form-control" required
                                   value="{{ old('title', $requisition?->title) }}"
                                   placeholder="{{ trans('admin/purchase-orders/general.builder_title_placeholder') }}">
                        </div>
                        <div class="col-md-3 form-group">
                            <label for="pob-supplier">{{ trans('general.supplier') }}</label>
                            <select name="supplier_id" id="pob-supplier" class="form-control">
                                <option value="">{{ trans('general.select_supplier') }}</option>
                                @foreach ($suppliers as $id => $name)
                                    <option value="{{ $id }}" {{ (int) old('supplier_id', $requisition?->supplier_id ?? $selectedSupplierId) === (int) $id ? 'selected' : '' }}>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 form-group">
                            <label for="pob-fy">{{ trans('admin/purchase-orders/general.fiscal_year') }}</label>
                            <select name="fiscal_year" id="pob-fy" class="form-control">
                                <option value="">{{ trans('general.na') }}</option>
                                @foreach ($fiscalYears as $fy)
                                    <option value="{{ $fy }}" {{ old('fiscal_year', $selectedFiscalYear) === $fy ? 'selected' : '' }}>{{ $fy }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 form-group">
                            <label for="pob-needed">{{ trans('admin/purchase-orders/general.requisition_needed_by') }}</label>
                            <input type="date" name="needed_by" id="pob-needed" class="form-control"
                                   value="{{ old('needed_by', $requisition?->needed_by?->format('Y-m-d')) }}">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 form-group">
                            <label for="pob-cc">{{ trans('admin/purchase-orders/general.cost_center') }}</label>
                            <input type="text" name="cost_center" id="pob-cc" class="form-control"
                                   value="{{ old('cost_center', $requisition?->cost_center) }}">
                        </div>
                        <div class="col-md-3 form-group">
                            <label for="pob-company">{{ trans('general.company') }}</label>
                            <select name="company_id" id="pob-company" class="form-control">
                                <option value="">{{ trans('general.na') }}</option>
                                @foreach ($companies as $id => $name)
                                    <option value="{{ $id }}" {{ (int) old('company_id', $requisition?->company_id) === (int) $id ? 'selected' : '' }}>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label for="pob-gl">{{ trans('admin/purchase-orders/general.gl_number') }}</label>
                            <input type="text" name="default_gl_number" id="pob-gl" class="form-control"
                                   value="{{ old('default_gl_number', $requisition?->default_gl_number) }}"
                                   placeholder="{{ trans('admin/purchase-orders/general.gl_number_placeholder') }}">
                            <p class="help-block">{{ trans('admin/purchase-orders/general.gl_number_help') }}</p>
                        </div>
                        <div class="col-md-3 form-group">
                            <label for="pob-notes">{{ trans('general.notes') }}</label>
                            <input type="text" name="notes" id="pob-notes" class="form-control"
                                   value="{{ old('notes', $requisition?->notes) }}">
                        </div>
                    </div>
                    {{-- Colleague keeps these apart and so do we: printer
                         comments are typeset onto the PO the vendor receives,
                         internal comments never leave the record. --}}
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="pob-printer-comments">{{ trans('admin/purchase-orders/general.printer_comments') }}</label>
                            <textarea name="printer_comments" id="pob-printer-comments" class="form-control" rows="3"
                                      placeholder="{{ trans('admin/purchase-orders/general.printer_comments_placeholder') }}">{{ old('printer_comments', $requisition?->printer_comments) }}</textarea>
                            <p class="help-block">{{ trans('admin/purchase-orders/general.printer_comments_help') }}</p>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="pob-internal-comments">{{ trans('admin/purchase-orders/general.internal_comments') }}</label>
                            <textarea name="internal_comments" id="pob-internal-comments" class="form-control" rows="3">{{ old('internal_comments', $requisition?->internal_comments) }}</textarea>
                            <p class="help-block">{{ trans('admin/purchase-orders/general.internal_comments_help') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- Catalog --}}
        <div class="col-md-7">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/purchase-orders/general.builder_catalog') }}</h3>
                </div>
                <div class="box-body">
                    @if ($catalog->isEmpty())
                        <p class="text-muted">{{ trans('admin/purchase-orders/general.builder_catalog_empty') }}</p>
                    @else
                        <div class="pob-filters">
                            <input type="text" id="pob-search" class="form-control"
                                   placeholder="{{ trans('admin/purchase-orders/general.builder_search') }}">
                            <select id="pob-type" class="form-control">
                                <option value="">{{ trans('admin/purchase-orders/general.builder_all_types') }}</option>
                                <option value="standard">{{ trans('admin/purchase-orders/general.builder_type_standard') }}</option>
                                <option value="cto">{{ trans('admin/purchase-orders/general.builder_type_cto') }}</option>
                            </select>
                        </div>

                        {{-- Categories are the coarsest cut through the shelf and
                             the one reached for most, so they get a row of their
                             own rather than a dropdown that hides what is on
                             offer behind a click. --}}
                        <div class="pob-cat-tabs" id="pob-category-tabs" role="tablist"
                             aria-label="{{ trans('admin/purchase-orders/general.builder_all_categories') }}">
                            <button type="button" class="pob-cat-tab active" data-category="" role="tab" aria-selected="true">
                                {{ trans('admin/purchase-orders/general.builder_all_categories') }}
                            </button>
                            @foreach ($categories as $category)
                                <button type="button" class="pob-cat-tab" data-category="{{ $category }}" role="tab" aria-selected="false">
                                    {{ $category }}
                                </button>
                            @endforeach
                        </div>

                        <div class="table-responsive pob-catalog-scroll">
                            <table class="table table-condensed pob-table">
                                <thead>
                                    <tr>
                                        <th>{{ trans('admin/purchase-orders/general.builder_col_description') }}</th>
                                        <th class="pob-num">{{ trans('admin/purchase-orders/general.builder_col_unit_cost') }}</th>
                                        <th class="pob-qty-col">{{ trans('admin/purchase-orders/general.builder_col_qty') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="pob-catalog-rows"></tbody>
                            </table>
                            <p class="text-muted pob-no-results" id="pob-no-results" hidden>
                                {{ trans('admin/purchase-orders/general.builder_no_results') }}
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Basket --}}
        <div class="col-md-5">
            <div class="box box-primary pob-basket-box">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/purchase-orders/general.builder_basket') }}</h3>
                </div>
                <div class="box-body">
                    <div class="table-responsive">
                        <table class="table table-condensed pob-table">
                            <thead>
                                <tr>
                                    <th>{{ trans('admin/purchase-orders/general.builder_col_description') }}</th>
                                    <th class="pob-qty-col">{{ trans('admin/purchase-orders/general.builder_col_qty') }}</th>
                                    <th class="pob-num">{{ trans('admin/purchase-orders/general.builder_col_unit_cost') }}</th>
                                    <th class="pob-num">{{ trans('admin/purchase-orders/general.builder_col_line_total') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="pob-basket-rows"></tbody>
                        </table>
                    </div>

                    <p class="text-muted" id="pob-basket-empty">{{ trans('admin/purchase-orders/general.builder_empty') }}</p>

                    <div class="alert alert-warning pob-estimate-alert" id="pob-estimate-alert" hidden>
                        {{ trans('admin/purchase-orders/general.requisition_estimate_warning') }}
                    </div>

                    <table class="table table-condensed pob-totals">
                        <tbody>
                            @if ($capital ?? null)
                                {{-- A capital-request basket edits against the
                                     FY's ending-schedule envelope: the budget
                                     row and the live gap under it are the
                                     comparison this page exists to make. --}}
                                <tr>
                                    <td>{{ trans('admin/purchase-orders/general.capital_envelope_row', ['fy' => $capital['fy']]) }}</td>
                                    <td class="pob-num" id="pob-envelope" data-envelope="{{ $capital['envelope'] }}">${{ number_format($capital['envelope'], 2) }}</td>
                                </tr>
                                <tr>
                                    <td>{{ trans('admin/purchase-orders/general.capital_envelope_gap') }}</td>
                                    <td class="pob-num" id="pob-envelope-gap">—</td>
                                </tr>
                            @endif
                            <tr>
                                <td>{{ trans('admin/purchase-orders/general.builder_subtotal') }}</td>
                                <td class="pob-num" id="pob-subtotal">—</td>
                            </tr>
                            <tr>
                                <td>
                                    <label for="pob-shipping" class="pob-inline-label">{{ trans('admin/purchase-orders/general.builder_shipping') }}</label>
                                </td>
                                <td class="pob-num">
                                    <input type="number" step="0.01" min="0" name="shipping" id="pob-shipping"
                                           class="form-control input-sm pob-num-input"
                                           value="{{ old('shipping', $requisition ? number_format((float) $requisition->shipping, 2, '.', '') : '0.00') }}">
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <label for="pob-gst-rate" class="pob-inline-label">{{ trans('admin/purchase-orders/general.builder_gst') }}</label>
                                    <input type="number" step="0.00001" min="0" max="1" name="gst_rate" id="pob-gst-rate"
                                           class="form-control input-sm pob-rate-input"
                                           value="{{ old('gst_rate', $requisition ? rtrim(rtrim((string) $requisition->gst_rate, '0'), '.') : '0.05') }}">
                                </td>
                                <td class="pob-num" id="pob-gst">—</td>
                            </tr>
                            <tr>
                                <td>
                                    <label for="pob-pst-rate" class="pob-inline-label">{{ trans('admin/purchase-orders/general.builder_pst') }}</label>
                                    <input type="number" step="0.00001" min="0" max="1" name="pst_rate" id="pob-pst-rate"
                                           class="form-control input-sm pob-rate-input"
                                           value="{{ old('pst_rate', $requisition ? rtrim(rtrim((string) $requisition->pst_rate, '0'), '.') : '0.07') }}">
                                </td>
                                <td class="pob-num" id="pob-pst">—</td>
                            </tr>
                            <tr class="pob-grand">
                                <td>{{ trans('admin/purchase-orders/general.builder_total') }}</td>
                                <td class="pob-num" id="pob-total">—</td>
                            </tr>
                        </tbody>
                    </table>

                    <button type="submit" class="btn btn-primary btn-block" id="pob-save" disabled>
                        {{ trans('admin/purchase-orders/general.builder_save') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- The generated purchase order.
         Colleague is not connected to this system and will not be, so the
         order has to be re-typed into it by hand. This panel is that keying
         surface: the finished PO laid out field by field, each one copyable
         on its own, so the transcription is a series of paste operations
         rather than a series of readings. It renders live off the basket —
         what is copied is always what is about to be saved. --}}
    <div class="row" id="pob-generated-row" hidden>
        <div class="col-md-12">
            <div class="box box-solid">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/purchase-orders/general.generated_po') }}</h3>
                    <div class="box-tools pull-right">
                        <button type="button" class="btn btn-xs btn-default" id="pob-copy-all">
                            <i class="far fa-copy" aria-hidden="true"></i>
                            {{ trans('admin/purchase-orders/general.copy_all') }}
                        </button>
                    </div>
                </div>
                <div class="box-body">
                    <p class="text-muted">{{ trans('admin/purchase-orders/general.generated_po_intro') }}</p>

                    <div class="row" id="pob-generated-header"></div>

                    <div class="table-responsive">
                        <table class="table table-condensed pob-table pob-generated-table">
                            <thead>
                                <tr>
                                    <th class="pob-num">{{ trans('admin/purchase-orders/general.builder_col_qty') }}</th>
                                    <th>{{ trans('admin/purchase-orders/general.unit_of_measure') }}</th>
                                    <th>{{ trans('admin/purchase-orders/general.builder_col_sku') }}</th>
                                    <th>{{ trans('admin/purchase-orders/general.builder_col_mfr') }}</th>
                                    <th>{{ trans('admin/purchase-orders/general.gl_number') }}</th>
                                    <th>{{ trans('admin/purchase-orders/general.builder_col_description') }}</th>
                                    <th class="pob-num">{{ trans('admin/purchase-orders/general.builder_col_unit_cost') }}</th>
                                    <th class="pob-num">{{ trans('admin/purchase-orders/general.builder_col_line_total') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="pob-generated-rows"></tbody>
                        </table>
                    </div>

                    <div class="row" id="pob-generated-totals"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Basket lines are serialised into this container on submit. --}}
    <div id="pob-line-inputs" hidden></div>
</form>

@include('partials.copy-fields')
@include('reports/procurement/_po-builder-js')

@stop
