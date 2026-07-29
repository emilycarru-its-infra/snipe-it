@extends('layouts/default')

@section('title')
    {{ $requisition->display_name }}
    @parent
@stop

@section('header_right')
    @if ($requisition->status === 'draft')
        <a href="{{ route('purchase-orders.builder', ['requisition' => $requisition->id]) }}" class="btn btn-sm btn-primary">
            {{ trans('admin/purchase-orders/general.requisition_open_builder') }}
        </a>
    @endif
    <a href="{{ route('requisitions.print', $requisition->id) }}" class="btn btn-sm btn-default" target="_blank" rel="noopener">
        {{ trans('admin/purchase-orders/general.requisition_print') }}
    </a>
    <a href="{{ route('requisitions.export', $requisition->id) }}" class="btn btn-sm btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
    <a href="{{ route('requisitions.index') }}" class="btn btn-sm btn-default">
        {{ trans('admin/purchase-orders/general.requisitions') }}
    </a>
@stop

@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ $requisition->title }}</h3>
                <div class="box-tools pull-right">
                    <span class="label label-default">{{ trans('admin/purchase-orders/general.requisition_status_'.$requisition->status) }}</span>
                </div>
            </div>
            <div class="box-body">
                @if ($requisition->printer_comments)
                    {{-- Printed onto the PO the vendor receives. --}}
                    <div class="well well-sm" style="white-space: pre-wrap;">
                        <strong>{{ trans('admin/purchase-orders/general.printer_comments') }}</strong><br>
                        {{ $requisition->printer_comments }}
                    </div>
                @endif

                @if ($requisition->internal_comments)
                    {{-- Never leaves the record. --}}
                    <div class="well well-sm" style="white-space: pre-wrap; background:#fbfbfb;">
                        <strong>{{ trans('admin/purchase-orders/general.internal_comments') }}</strong><br>
                        {{ $requisition->internal_comments }}
                    </div>
                @endif

                @if ($requisition->hasEstimatedLines())
                    <div class="alert alert-warning">
                        {{ trans('admin/purchase-orders/general.requisition_estimate_warning') }}
                    </div>
                @endif

                <div class="table-responsive">
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>{{ trans('admin/purchase-orders/general.builder_col_sku') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.builder_col_mfr') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.gl_number') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.builder_col_description') }}</th>
                                <th class="text-right">{{ trans('admin/purchase-orders/general.builder_col_qty') }}</th>
                                <th class="text-right">{{ trans('admin/purchase-orders/general.builder_col_unit_cost') }}</th>
                                <th class="text-right">{{ trans('admin/purchase-orders/general.builder_col_line_total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($requisition->items as $line)
                                <tr>
                                    <td>{{ $line->vendor_sku ?: trans('general.na') }}</td>
                                    <td>{{ $line->mfr_part_number ?: trans('general.na') }}</td>
                                    <td>{{ $line->gl_number ?: ($requisition->default_gl_number ?: trans('general.na')) }}</td>
                                    <td>
                                        {{ $line->description }}
                                        @if ($line->isEstimate())
                                            <span class="label label-warning">{{ trans('admin/purchase-orders/general.builder_estimate_badge') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ $line->quantity }} {{ $line->unit_of_measure ?: 'EA' }}</td>
                                    <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($line->unit_cost) }}</td>
                                    <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($line->lineTotal()) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" class="text-right">{{ trans('admin/purchase-orders/general.builder_subtotal') }}</td>
                                <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->subtotal()) }}</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="text-right">{{ trans('admin/purchase-orders/general.builder_shipping') }}</td>
                                <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->shipping) }}</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="text-right">{{ trans('admin/purchase-orders/general.builder_gst') }}</td>
                                <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->gstAmount()) }}</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="text-right">{{ trans('admin/purchase-orders/general.builder_pst') }}</td>
                                <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->pstAmount()) }}</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="text-right"><strong>{{ trans('admin/purchase-orders/general.builder_total') }}</strong></td>
                                <td class="text-right"><strong>{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->total()) }}</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        {{-- The step that closes the loop with Colleague: the requisition goes
             out as a keying sheet and comes back with a REQM number, then
             later a PO number. Recording either one advances the status. --}}
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.requisition_record_reqm') }}</h3>
            </div>
            <form method="POST" action="{{ route('requisitions.update', $requisition->id) }}">
                {{ csrf_field() }}
                @method('PATCH')
                <div class="box-body">
                    <div class="form-group">
                        <label for="req-number">{{ trans('admin/purchase-orders/general.requisition_number') }}</label>
                        <input type="text" name="requisition_number" id="req-number" class="form-control"
                               value="{{ old('requisition_number', $requisition->requisition_number) }}"
                               placeholder="{{ trans('admin/purchase-orders/general.requisition_number_placeholder') }}">
                        <p class="help-block">{{ trans('admin/purchase-orders/general.requisition_number_help') }}</p>
                    </div>
                    <div class="form-group">
                        <label for="req-po">{{ trans('admin/purchase-orders/general.po_number') }}</label>
                        <select name="purchase_order_id" id="req-po" class="form-control">
                            <option value="">{{ trans('general.na') }}</option>
                            @foreach ($purchaseOrders as $id => $poNumber)
                                <option value="{{ $id }}" {{ (int) old('purchase_order_id', $requisition->purchase_order_id) === (int) $id ? 'selected' : '' }}>{{ $poNumber }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="req-status">{{ trans('general.status') }}</label>
                        <select name="status" id="req-status" class="form-control">
                            @foreach (\App\Models\Requisition::STATUSES as $status)
                                <option value="{{ $status }}" {{ $requisition->status === $status ? 'selected' : '' }}>
                                    {{ trans('admin/purchase-orders/general.requisition_status_'.$status) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="req-notes">{{ trans('general.notes') }}</label>
                        <textarea name="notes" id="req-notes" class="form-control" rows="3">{{ old('notes', $requisition->notes) }}</textarea>
                    </div>
                </div>
                <div class="box-footer">
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </form>
        </div>

        <div class="box box-default">
            <div class="box-body">
                <table class="table table-condensed">
                    <tbody>
                        <tr>
                            <td>{{ trans('general.supplier') }}</td>
                            <td>{{ $requisition->supplier?->name ?: trans('general.na') }}</td>
                        </tr>
                        <tr>
                            <td>{{ trans('general.company') }}</td>
                            <td>{{ $requisition->company?->name ?: trans('general.na') }}</td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.fiscal_year') }}</td>
                            <td>{{ $requisition->fiscal_year ?: trans('general.na') }}</td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.cost_center') }}</td>
                            <td>{{ $requisition->cost_center ?: trans('general.na') }}</td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.requisition_needed_by') }}</td>
                            <td>{{ $requisition->needed_by?->format('Y-m-d') ?: trans('general.na') }}</td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.gl_number') }}</td>
                            <td>{{ $requisition->default_gl_number ?: trans('general.na') }}</td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.requisition_created_by') }}</td>
                            <td>{{ $requisition->adminuser?->present()->fullName ?: trans('general.na') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@stop
