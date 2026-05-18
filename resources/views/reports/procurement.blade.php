@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/purchase-orders/general.reports') }}
    @parent
@stop

{{-- Page content --}}
@section('content')
<div class="row">
    <div class="col-lg-8 col-lg-offset-2 col-md-10 col-md-offset-1 col-sm-12 col-sm-offset-0">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">{{ trans('admin/purchase-orders/general.reports') }}</h2>
            </div>
            <div class="box-body">
                <p class="text-muted">{{ trans('admin/purchase-orders/general.reports_intro') }}</p>
                <table class="table table-striped">
                    <tbody>
                    @foreach ([
                        ['route' => 'reports.procurement.po-budget', 'name' => 'report_po_budget', 'desc' => 'report_po_budget_desc'],
                        ['route' => 'reports.procurement.invoices', 'name' => 'report_invoices', 'desc' => 'report_invoices_desc'],
                        ['route' => 'reports.procurement.receiving', 'name' => 'report_receiving', 'desc' => 'report_receiving_desc'],
                        ['route' => 'reports.procurement.tax', 'name' => 'report_tax', 'desc' => 'report_tax_desc'],
                        ['route' => 'reports.procurement.capital', 'name' => 'report_capital', 'desc' => 'report_capital_desc'],
                        ['route' => 'reports.procurement.forecast', 'name' => 'report_forecast', 'desc' => 'report_forecast_desc'],
                    ] as $report)
                        <tr>
                            <td>
                                <strong>{{ trans('admin/purchase-orders/general.'.$report['name']) }}</strong><br>
                                <span class="text-muted">{{ trans('admin/purchase-orders/general.'.$report['desc']) }}</span>
                            </td>
                            <td class="text-right" style="vertical-align:middle">
                                <a href="{{ route($report['route']) }}" class="btn btn-sm btn-primary">
                                    <x-icon type="download" /> {{ trans('general.download') }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@stop
