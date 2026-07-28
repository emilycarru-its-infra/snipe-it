@extends('layouts/default')

@section('title')
    {{ trans('admin/purchase-orders/general.requisitions') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('purchase-orders.builder') }}" class="btn btn-sm btn-primary">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> {{ trans('admin/purchase-orders/general.report_po_builder') }}
    </a>
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.requisitions') }}</h3>
                <div class="box-tools pull-right">
                    <form method="get">
                        <select name="status" class="form-control input-sm" onchange="this.form.submit()">
                            <option value="">{{ trans('general.all') }}</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" {{ $selectedStatus === $status ? 'selected' : '' }}>
                                    {{ trans('admin/purchase-orders/general.requisition_status_'.$status) }}
                                </option>
                            @endforeach
                        </select>
                    </form>
                </div>
            </div>
            <div class="box-body">
                @if ($requisitions->isEmpty())
                    <p class="text-muted">{{ trans('admin/purchase-orders/general.requisition_none') }}</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped table-condensed">
                            <thead>
                                <tr>
                                    <th>{{ trans('admin/purchase-orders/general.requisition_number') }}</th>
                                    <th>{{ trans('admin/purchase-orders/general.builder_title') }}</th>
                                    <th>{{ trans('general.status') }}</th>
                                    <th>{{ trans('general.supplier') }}</th>
                                    <th>{{ trans('admin/purchase-orders/general.fiscal_year') }}</th>
                                    <th class="text-right">{{ trans('admin/purchase-orders/general.requisition_lines') }}</th>
                                    <th class="text-right">{{ trans('admin/purchase-orders/general.builder_total') }}</th>
                                    <th>{{ trans('admin/purchase-orders/general.po_number') }}</th>
                                    <th>{{ trans('admin/purchase-orders/general.requisition_created_by') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($requisitions as $requisition)
                                    <tr>
                                        <td>
                                            <a href="{{ route('requisitions.show', $requisition->id) }}">
                                                {{ $requisition->requisition_number ?: trans('admin/purchase-orders/general.requisition_status_draft') }}
                                            </a>
                                        </td>
                                        <td>{{ $requisition->title }}</td>
                                        <td>{{ trans('admin/purchase-orders/general.requisition_status_'.$requisition->status) }}</td>
                                        <td>{{ $requisition->supplier?->name ?: trans('general.na') }}</td>
                                        <td>{{ $requisition->fiscal_year ?: trans('general.na') }}</td>
                                        <td class="text-right">{{ $requisition->items->count() }}</td>
                                        <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->total()) }}</td>
                                        <td>{{ $requisition->purchaseOrder?->po_number ?: trans('general.na') }}</td>
                                        <td>{{ $requisition->adminuser?->present()->fullName ?: trans('general.na') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $requisitions->links() }}
                @endif
            </div>
        </div>
    </div>
</div>
@stop
