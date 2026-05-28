@extends('layouts/default')

@section('title')
    {{ trans('admin/forms/general.title') }}
    @parent
@stop

@section('content')

<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <h1 style="margin-top:0;">{{ trans('admin/forms/general.title') }}</h1>
        <p class="text-muted">{{ trans('admin/forms/general.intro') }}</p>

        @if (empty($forms))
            <div class="alert alert-info">{{ trans('admin/forms/general.no_forms') }}</div>
        @else
            <div class="row">
                @foreach ($forms as $slug => $entry)
                    @include('forms._tile', [
                        'slug'    => $slug,
                        'meta'    => $entry['meta'],
                        'isAdmin' => $entry['admin'],
                        'canSubmit' => $entry['submit'],
                    ])
                @endforeach
            </div>
        @endif
    </div>
</div>

@stop
