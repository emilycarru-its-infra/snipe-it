@extends('layouts/default')

@section('title')
{{ trans('admin/email-log/general.title') }}
@parent
@stop

@section('content')
    <x-container>
        <x-box>
            <p class="help-block">{{ trans('admin/email-log/general.help') }}</p>

            <form method="GET" action="{{ route('reports.emails') }}" class="form-inline" style="margin-bottom: 15px;">
                <div class="form-group">
                    <label class="sr-only" for="email-log-search">{{ trans('general.search') }}</label>
                    <input type="search" id="email-log-search" name="search" class="form-control" value="{{ $search }}" placeholder="{{ trans('admin/email-log/general.search_placeholder') }}">
                </div>
                <div class="form-group">
                    <label class="sr-only" for="email-log-type">{{ trans('admin/email-log/general.record') }}</label>
                    <select id="email-log-type" name="type" class="form-control">
                        <option value="">{{ trans('admin/email-log/general.any_record') }}</option>
                        @foreach ($types as $option)
                            <option value="{{ $option }}" @selected($type === $option)>{{ trans('admin/email-log/general.type_'.$option) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="sr-only" for="email-log-id">{{ trans('admin/email-log/general.record_id') }}</label>
                    <input type="number" min="1" id="email-log-id" name="id" class="form-control" value="{{ $id }}" placeholder="{{ trans('admin/email-log/general.record_id') }}" style="width: 110px">
                </div>
                <button type="submit" class="btn btn-primary">{{ trans('general.search') }}</button>
            </form>

            @include('partials.email-log-table', ['emails' => $emails])

            {{ $emails->links() }}
        </x-box>
    </x-container>
@stop
