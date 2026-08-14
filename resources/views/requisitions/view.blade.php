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
                            {{-- Every value is copyable: this table is what
                                 gets re-typed into Colleague line by line, so
                                 each cell is a paste source rather than
                                 something to read off the screen. --}}
                            @foreach ($requisition->items as $line)
                                @php $gl = $line->gl_number ?: $requisition->default_gl_number; @endphp
                                <tr>
                                    <td><x-copy-field :value="$line->vendor_sku" /></td>
                                    <td><x-copy-field :value="$line->mfr_part_number" /></td>
                                    <td><x-copy-field :value="$gl" /></td>
                                    <td>
                                        <x-copy-field :value="$line->description" />
                                        @if ($line->isEstimate())
                                            <span class="label label-warning">{{ trans('admin/purchase-orders/general.builder_estimate_badge') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <x-copy-field :value="$line->quantity" /> {{ $line->unit_of_measure ?: 'EA' }}
                                    </td>
                                    <td class="text-right">
                                        <x-copy-field :value="number_format((float) $line->unit_cost, 2, '.', '')"
                                                      :display="\App\Helpers\Helper::formatCurrencyOutput($line->unit_cost)" />
                                    </td>
                                    <td class="text-right">
                                        <x-copy-field :value="number_format($line->lineTotal(), 2, '.', '')"
                                                      :display="\App\Helpers\Helper::formatCurrencyOutput($line->lineTotal())" />
                                    </td>
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
                        <label for="req-status">{{ trans('general.status') }}</label>
                        <select name="status" id="req-status" class="form-control">
                            @foreach (\App\Models\Requisition::STATUSES as $status)
                                <option value="{{ $status }}" {{ $requisition->status === $status ? 'selected' : '' }}
                                        {{ ($status === 'ordered' && ! $requisition->purchase_order_id) ? 'disabled' : '' }}>
                                    {{ trans('admin/purchase-orders/general.requisition_status_'.$status) }}
                                </option>
                            @endforeach
                        </select>
                        @unless ($requisition->purchase_order_id)
                            <p class="help-block">{{ trans('admin/purchase-orders/general.promote_required_for_ordered') }}</p>
                        @endunless
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

        {{-- The crossing into the budget ledger.

             Up to here nothing this requisition says has moved a single
             number in the procurement reports — that is the point of keeping
             baskets out of the purchase_orders table. Promotion is where it
             starts counting, so it is gated on the PDF finance emailed: a
             purchase order that can hold budget without the document that
             issued its number is an entry the reports can't later explain. --}}
        @if ($requisition->purchase_order_id)
            <div class="box box-success">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/purchase-orders/general.po_number') }}</h3>
                </div>
                <div class="box-body">
                    <p>
                        <a class="js-lightbox" href="{{ route('purchase-orders.show', $requisition->purchase_order_id) }}">
                            {{ $requisition->purchaseOrder?->po_number }}
                        </a>
                    </p>
                    <p class="text-muted">{{ trans('admin/purchase-orders/general.promoted_help') }}</p>
                </div>
            </div>

            {{-- The send lives on the purchase order now. That is the document
                 that authorises the spending and the one the vendor bills
                 against; this record is the basket that produced it, kept for
                 tracking. --}}
            <div class="box box-primary">
                <div class="box-body">
                    <p>{{ trans('admin/purchase-orders/general.order_from_po_help') }}</p>
                    <a class="js-lightbox" href="{{ route('purchase-orders.show', $requisition->purchaseOrder) }}" class="btn btn-primary btn-block">
                        {{ trans('admin/purchase-orders/general.order_from_po_link', ['po' => $requisition->purchaseOrder?->po_number]) }}
                    </a>
                </div>
            </div>
        @elseif ($requisition->status !== 'cancelled')
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/purchase-orders/general.promote_title') }}</h3>
                </div>
                <form method="POST" action="{{ route('requisitions.promote', $requisition->id) }}"
                      enctype="multipart/form-data">
                    {{ csrf_field() }}
                    <div class="box-body">
                        <p class="text-muted">{{ trans('admin/purchase-orders/general.promote_help') }}</p>

                        <div class="form-group">
                            <label for="promote-po-number">{{ trans('admin/purchase-orders/general.po_number') }}</label>
                            <input type="text" name="po_number" id="promote-po-number" class="form-control"
                                   value="{{ old('po_number') }}"
                                   placeholder="{{ trans('admin/purchase-orders/general.promote_po_number_placeholder') }}">
                        </div>

                        {{-- The vendor feed sometimes lands a PO here before we
                             get to it; linking that row is the alternative to
                             minting a duplicate. --}}
                        <div class="form-group">
                            <label for="promote-existing">{{ trans('admin/purchase-orders/general.promote_link_existing') }}</label>
                            <select name="purchase_order_id" id="promote-existing" class="form-control">
                                <option value="">{{ trans('admin/purchase-orders/general.promote_create_new') }}</option>
                                @foreach ($purchaseOrders as $id => $poNumber)
                                    <option value="{{ $id }}" {{ (int) old('purchase_order_id') === (int) $id ? 'selected' : '' }}>{{ $poNumber }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="promote-budget">{{ trans('admin/purchase-orders/general.budget') }}</label>
                            <input type="number" step="0.01" min="0" name="budget" id="promote-budget" class="form-control"
                                   value="{{ old('budget', number_format($requisition->total(), 2, '.', '')) }}">
                            <p class="help-block">{{ trans('admin/purchase-orders/general.promote_budget_help') }}</p>
                        </div>

                        <div class="form-group">
                            <label for="promote-fy">{{ trans('admin/purchase-orders/general.fiscal_year') }}</label>
                            <select name="fiscal_year" id="promote-fy" class="form-control">
                                @foreach ($fiscalYears as $fy)
                                    <option value="{{ $fy }}" {{ old('fiscal_year', $requisition->fiscal_year ?: \App\Helpers\Helper::currentFiscalYear()) === $fy ? 'selected' : '' }}>{{ $fy }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="promote-order-date">{{ trans('admin/purchase-orders/general.order_date') }}</label>
                            <input type="date" name="order_date" id="promote-order-date" class="form-control"
                                   value="{{ old('order_date', now()->format('Y-m-d')) }}">
                        </div>

                        <div class="form-group {{ $errors->has('document') ? ' has-error' : '' }}">
                            <label for="promote-document">
                                {{ trans('admin/purchase-orders/general.promote_document') }}
                                <span class="text-danger">*</span>
                            </label>
                            <input type="file" name="document" id="promote-document" accept="application/pdf" required>
                            <p class="help-block">{{ trans('admin/purchase-orders/general.promote_document_help') }}</p>
                            {!! $errors->first('document', '<span class="help-block">:message</span>') !!}
                        </div>
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-warning btn-block">
                            {{ trans('admin/purchase-orders/general.promote_submit') }}
                        </button>
                    </div>
                </form>
            </div>
        @endif

        <div class="box box-default">
            <div class="box-body">
                <table class="table table-condensed">
                    <tbody>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.builder_title') }}</td>
                            <td><x-copy-field :value="$requisition->title" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('general.supplier') }}</td>
                            <td><x-copy-field :value="$requisition->supplier?->name" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('general.company') }}</td>
                            <td><x-copy-field :value="$requisition->company?->name" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.fiscal_year') }}</td>
                            <td><x-copy-field :value="$requisition->fiscal_year" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.cost_center') }}</td>
                            <td><x-copy-field :value="$requisition->cost_center" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.requisition_needed_by') }}</td>
                            <td><x-copy-field :value="$requisition->needed_by?->format('Y-m-d')" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.gl_number') }}</td>
                            <td><x-copy-field :value="$requisition->default_gl_number" /></td>
                        </tr>
                        <tr>
                            <td>{{ trans('admin/purchase-orders/general.builder_total') }}</td>
                            <td>
                                <x-copy-field :value="number_format($requisition->total(), 2, '.', '')"
                                              :display="\App\Helpers\Helper::formatCurrencyOutput($requisition->total())" />
                            </td>
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

@include('partials.copy-fields')
@stop
