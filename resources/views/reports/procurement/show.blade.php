@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ $reportTitle }}
    @parent
@stop

{{-- Page content --}}
@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">{{ $reportTitle }}</h2>
                <div class="pull-right">
                    {!! $controls ?? '' !!}
                    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default">
                        <x-icon type="download" /> {{ trans('general.download') }}
                    </a>
                    <a href="{{ route('reports.procurement') }}" class="btn btn-sm btn-default">
                        {{ trans('admin/purchase-orders/general.reports') }}
                    </a>
                </div>
            </div>
            <div class="box-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                @foreach ($columns as $col)
                                    <th>{{ $col }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($rows as $row)
                            <tr @if (! empty($row['class'])) class="{{ $row['class'] }}" @endif>
                                @foreach ($row['cells'] as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($columns) }}">{{ trans('general.no_results') }}</td>
                            </tr>
                        @endforelse
                        </tbody>
                        @if (! empty($footer))
                            <tfoot>
                                <tr>
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
    </div>
</div>
@stop
