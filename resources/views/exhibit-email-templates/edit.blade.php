@extends('layouts/default')

@section('title')
    {{ $template->name }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-9">
        <form class="form-horizontal" method="POST" action="{{ route('exhibit-email-templates.update', $template) }}">
            {{ csrf_field() }}
            @method('PUT')
            <div class="box box-default">
                <div class="box-header with-border"><h3 class="box-title">{{ $template->name }}</h3></div>
                <div class="box-body">
                    <div class="form-group {{ $errors->has('subject') ? 'has-error' : '' }}">
                        <label for="subject" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.template_subject') }}</label>
                        <div class="col-md-9"><input type="text" id="subject" name="subject" class="form-control" value="{{ old('subject', $template->subject) }}"></div>
                    </div>
                    <div class="form-group {{ $errors->has('body') ? 'has-error' : '' }}">
                        <label for="body" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.template_body') }}</label>
                        <div class="col-md-9"><textarea id="body" name="body" rows="18" class="form-control" style="font-family: var(--bs-font-monospace, monospace);">{{ old('body', $template->body) }}</textarea></div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.template_enabled') }}</label>
                        <div class="col-md-9"><label class="checkbox-inline"><input type="checkbox" name="enabled" value="1" {{ old('enabled', $template->enabled) ? 'checked' : '' }}></label></div>
                    </div>
                </div>
                <div class="box-footer text-right">
                    <a class="btn btn-default" href="{{ route('exhibit-email-templates.index') }}">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </div>
        </form>
    </div>
    <div class="col-md-3">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">{{ trans('admin/exhibit-projects/general.merge_vars') }}</h3></div>
            <div class="box-body">
                <p class="text-muted">{{ trans('admin/exhibit-projects/general.merge_vars_help') }}</p>
                <ul class="list-unstyled">
                    <li><code>{{ '{{student_name}}' }}</code></li>
                    <li><code>{{ '{{show}}' }}</code></li>
                    <li><code>{{ '{{year}}' }}</code></li>
                    <li><code>{{ '{{project_type}}' }}</code></li>
                    <li><code>{{ '{{requested_device}}' }}</code></li>
                    <li><code>{{ '{{peripherals}}' }}</code></li>
                    <li><code>{{ '{{assigned_asset}}' }}</code></li>
                </ul>
            </div>
        </div>
    </div>
</div>
@stop
