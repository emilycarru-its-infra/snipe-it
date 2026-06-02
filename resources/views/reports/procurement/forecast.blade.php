@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ $reportTitle }}
    @parent
@stop

{{-- Page-header actions --}}
@section('header_right')
    @if (! empty($fyFilterable))
        <form method="get" style="display:inline-block; margin-right:4px;">
            <select name="fiscal_year" class="form-control input-sm" style="display:inline-block; width:auto;" onchange="this.form.submit()">
                <option value="all" {{ ($selectedFy ?? null) === null ? 'selected' : '' }}>{{ trans('admin/purchase-orders/general.all_fiscal_years') }}</option>
                @foreach (($allFiscalYears ?? collect()) as $fy)
                    <option value="{{ $fy }}" {{ ($selectedFy ?? null) === $fy ? 'selected' : '' }}>{{ $fy }}</option>
                @endforeach
            </select>
        </form>
    @endif
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
    <a href="{{ route('reports.procurement') }}" class="btn btn-sm btn-default">
        {{ trans('admin/purchase-orders/general.reports') }}
    </a>
@stop

{{-- Page content --}}
@section('content')
<div class="row">
    <div class="col-md-12">
        <form method="POST" action="{{ route('reports.procurement.forecast.plan') }}">
            {{ csrf_field() }}
            <div class="box box-default">
                <div class="box-body">
                    @if ($canCreate)
                        <p>{{ trans('admin/purchase-orders/general.forecast_intro') }}</p>
                        <div class="form-inline" style="margin-bottom: 15px;">
                            <div class="form-group {{ $errors->has('order_number') ? 'has-error' : '' }}">
                                <label for="order_number">{{ trans('admin/purchase-orders/general.forecast_order_number') }}</label>
                                <input type="text" name="order_number" id="order_number" class="form-control"
                                       value="{{ old('order_number') }}" maxlength="191" required>
                            </div>
                            <div class="form-group {{ $errors->has('fiscal_year') ? 'has-error' : '' }}">
                                <label for="fiscal_year">{{ trans('admin/purchase-orders/general.fiscal_year') }}</label>
                                <input type="text" name="fiscal_year" id="fiscal_year" class="form-control"
                                       value="{{ old('fiscal_year') }}" maxlength="191">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                {{ trans('admin/purchase-orders/general.forecast_create_planned') }}
                            </button>
                        </div>
                    @endif
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    @if ($canCreate)
                                        <th>{{ trans('admin/purchase-orders/general.forecast_select') }}</th>
                                    @endif
                                    @foreach ($columns as $col)
                                        <th>{{ $col }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($rows as $row)
                                <tr @if (! empty($row['class'])) class="{{ $row['class'] }}" @endif>
                                    @if ($canCreate)
                                        <td>
                                            @if (! empty($row['planned']))
                                                <span class="label label-info">{{ trans('admin/purchase-orders/general.forecast_planned_already') }}</span>
                                            @else
                                                <input type="checkbox" name="assets[]" value="{{ $row['asset_id'] }}">
                                            @endif
                                        </td>
                                    @endif
                                    @foreach ($row['cells'] as $cell)
                                        <td>{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($columns) + ($canCreate ? 1 : 0) }}">{{ trans('general.no_results') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                            @if (! empty($footer))
                                <tfoot>
                                    <tr>
                                        @if ($canCreate)<th></th>@endif
                                        @foreach ($footer as $cell)
                                            <th>{{ $cell }}</th>
                                        @endforeach
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
@stop
