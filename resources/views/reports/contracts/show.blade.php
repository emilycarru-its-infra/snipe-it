@extends('layouts/default')

@section('title')
    {{ $reportTitle }}
    @parent
@stop

@section('header_right')
    {!! $controls ?? '' !!}
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
    <a href="{{ route('reports.contracts') }}" class="btn btn-sm btn-default">
        {{ trans('admin/contracts/general.reports') }}
    </a>
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
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
