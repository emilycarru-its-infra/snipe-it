@extends('layouts/default')

@section('title')
    {{ trans('admin/exhibit-projects/general.email_templates') }} @parent
@stop

@section('header_right')
    <a href="{{ route('reports.exhibit') }}" class="btn btn-sm btn-default">{{ trans('admin/exhibit-projects/general.dashboard_title') }}</a>
@stop

@section('content')
<div class="box box-default">
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('admin/exhibit-projects/general.template_name') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.template_subject') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.template_enabled') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($templates as $template)
                <tr>
                    <td><strong>{{ $template->name }}</strong></td>
                    <td>{{ $template->subject }}</td>
                    <td>@if ($template->enabled)<i class="fas fa-check text-success"></i>@else <i class="fas fa-times text-muted"></i> @endif</td>
                    <td class="text-right"><a href="{{ route('exhibit-email-templates.edit', $template) }}" class="btn btn-xs btn-warning"><i class="fas fa-pencil-alt"></i> {{ trans('general.update') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted">—</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@stop
