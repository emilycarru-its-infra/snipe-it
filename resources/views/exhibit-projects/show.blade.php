@extends('layouts/default')

@section('title')
    {{ trans('admin/exhibit-projects/general.project') }} #{{ $project->id }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ $project->displayName }} — {{ $project->show }} {{ $project->year }}</h3>
                <div class="box-tools pull-right">
                    <span class="label label-{{ $project->statusColor() }}">{{ trans('admin/exhibit-projects/general.status_value_'.$project->status) }}</span>
                </div>
            </div>
            <div class="box-body">
                <dl class="dl-horizontal">
                    <dt>{{ trans('admin/exhibit-projects/general.student') }}</dt>
                    <dd>
                        @if ($project->user)
                            <a href="{{ route('users.show', $project->user) }}">{{ $project->user->full_name }}</a>
                            @if ($project->user->email) <span class="text-muted">({{ $project->user->email }})</span> @endif
                        @else
                            {{ $project->student_name ?: '—' }}
                        @endif
                    </dd>
                    <dt>{{ trans('admin/exhibit-projects/general.asset') }}</dt>
                    <dd>
                        @if ($project->asset)
                            <a href="{{ route('hardware.show', $project->asset) }}">{{ $project->assignedDeviceLabel() }}</a>
                        @else — @endif
                    </dd>
                    <dt>{{ trans('admin/exhibit-projects/general.project_type') }}</dt>
                    <dd>{{ $project->project_type ? trans('admin/exhibit-projects/general.type_value_'.$project->project_type) : '—' }}</dd>
                    <dt>{{ trans('admin/exhibit-projects/general.project_details') }}</dt>
                    <dd>{{ $project->project_details ?: '—' }}</dd>
                    <dt>{{ trans('admin/exhibit-projects/general.requested_device') }}</dt>
                    <dd>{{ $project->requested_device ?: '—' }}</dd>
                    <dt>{{ trans('admin/exhibit-projects/general.peripherals') }}</dt>
                    <dd>{{ $project->peripherals ?: '—' }}</dd>
                    <dt>{{ trans('admin/exhibit-projects/general.submitted_file') }}</dt>
                    <dd>@if ($project->submitted_file)<i class="fas fa-check text-success"></i>@else <i class="fas fa-times text-muted"></i> @endif</dd>
                    <dt>{{ trans('admin/exhibit-projects/general.approved') }}</dt>
                    <dd>@if ($project->approved)<i class="fas fa-check text-success"></i>@else <i class="fas fa-times text-muted"></i> @endif</dd>
                    <dt>{{ trans('admin/exhibit-projects/general.tdx_id') }}</dt>
                    <dd>{{ $project->tdx_id ?: '—' }}</dd>
                    <dt>{{ trans('admin/exhibit-projects/general.notes') }}</dt>
                    <dd>{{ $project->notes ?: '—' }}</dd>
                </dl>
            </div>
            <div class="box-footer">
                <a class="btn btn-warning" href="{{ route('exhibit-projects.edit', $project) }}"><i class="fas fa-pencil-alt"></i> {{ trans('general.update') }}</a>
                <form method="POST" action="{{ route('exhibit-projects.destroy', $project) }}" style="display:inline-block;" onsubmit="return confirm('{{ trans('admin/exhibit-projects/general.delete_confirm') }}');">
                    {{ csrf_field() }}
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> {{ trans('general.delete') }}</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/exhibit-projects/general.send_email') }}</h3>
            </div>
            <div class="box-body">
                @if (! $project->recipientEmail())
                    <p class="text-muted">{{ trans('admin/exhibit-projects/general.email_no_recipient') }}</p>
                @elseif ($templates->isEmpty())
                    <p class="text-muted">—</p>
                @else
                    <form method="POST" action="{{ route('exhibit-projects.email', $project) }}">
                        {{ csrf_field() }}
                        <div class="form-group">
                            <select name="template_id" class="form-control">
                                @foreach ($templates as $template)
                                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-paper-plane"></i> {{ trans('admin/exhibit-projects/general.send_email') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@stop
