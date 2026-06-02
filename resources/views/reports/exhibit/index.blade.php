@extends('layouts/default')

@section('title')
    {{ trans('admin/exhibit-projects/general.dashboard_title') }} @parent
@stop

@section('header_right')
    <a href="{{ route('exhibit-email-templates.index') }}" class="btn btn-sm btn-default">
        <i class="fas fa-envelope"></i> {{ trans('admin/exhibit-projects/general.email_templates') }}
    </a>
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default">
        <i class="fas fa-download"></i> {{ trans('general.download') }}
    </a>
    <a href="{{ route('exhibit-projects.create') }}" class="btn btn-sm btn-primary">
        <i class="fas fa-plus"></i> {{ trans('admin/exhibit-projects/general.add_project') }}
    </a>
@stop

@section('content')

{{-- Filters --}}
<div class="row">
    <div class="col-md-12">
        <form method="GET" action="{{ route('reports.exhibit') }}" class="form-inline" style="margin-bottom:15px;">
            <div class="form-group">
                <label>{{ trans('admin/exhibit-projects/general.filter_show') }}</label>
                <select name="show" class="form-control" onchange="this.form.submit()">
                    @foreach ($shows as $s)
                        <option value="{{ $s }}" {{ $show === $s ? 'selected' : '' }}>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label>{{ trans('admin/exhibit-projects/general.filter_year') }}</label>
                <select name="year" class="form-control" onchange="this.form.submit()">
                    @foreach ($years as $y)
                        <option value="{{ $y }}" {{ (int) $year === (int) $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label>{{ trans('admin/exhibit-projects/general.filter_status') }}</label>
                <select name="status" class="form-control" onchange="this.form.submit()">
                    <option value="">{{ trans('admin/exhibit-projects/general.all_statuses') }}</option>
                    @foreach (\App\Models\ExhibitProject::STATUSES as $st)
                        <option value="{{ $st }}" {{ $statusFilter === $st ? 'selected' : '' }}>{{ trans('admin/exhibit-projects/general.status_value_'.$st) }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>
</div>

{{-- Count widgets --}}
<div class="row">
    @php($cards = [
        ['title' => trans('admin/exhibit-projects/general.widget_project_type'), 'rows' => $widgets['type'], 'status' => false],
        ['title' => trans('admin/exhibit-projects/general.widget_status'), 'rows' => $widgets['status'], 'status' => true],
        ['title' => trans('admin/exhibit-projects/general.widget_device'), 'rows' => $widgets['device'], 'status' => false],
    ])
    @foreach ($cards as $card)
        <div class="col-md-4">
            <div class="box box-default">
                <div class="box-header with-border"><h3 class="box-title">{{ $card['title'] }}</h3></div>
                <div class="box-body no-padding">
                    <table class="table table-striped">
                        <thead><tr><th></th><th class="text-right">{{ trans('admin/exhibit-projects/general.count') }}</th><th class="text-right">%</th></tr></thead>
                        <tbody>
                        @forelse ($card['rows'] as $row)
                            <tr>
                                <td>
                                    @if ($card['status'])
                                        <span class="label label-{{ $row['color'] }}">{{ $row['label'] }}</span>
                                    @else
                                        {{ $row['label'] }}
                                    @endif
                                </td>
                                <td class="text-right"><strong>{{ $row['count'] }}</strong></td>
                                <td class="text-right text-muted">{{ $row['pct'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted">—</td></tr>
                        @endforelse
                        </tbody>
                        <tfoot><tr><th>{{ trans('admin/exhibit-projects/general.total') }}</th><th class="text-right">{{ $widgets['total'] }}</th><th></th></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- Bulk confirmation email to all approved --}}
@if ($templates->isNotEmpty())
<div class="row">
    <div class="col-md-12" style="margin-bottom:10px;">
        <form method="POST" action="{{ route('exhibit-projects.send-bulk') }}" class="form-inline" onsubmit="return confirm('{{ trans('admin/exhibit-projects/general.send_to_approved') }}?');">
            {{ csrf_field() }}
            <input type="hidden" name="show" value="{{ $show }}">
            <input type="hidden" name="year" value="{{ $year }}">
            <div class="form-group">
                <select name="template_id" class="form-control">
                    @foreach ($templates as $template)
                        <option value="{{ $template->id }}">{{ $template->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-default"><i class="fas fa-paper-plane"></i> {{ trans('admin/exhibit-projects/general.send_to_approved') }}</button>
        </form>
    </div>
</div>
@endif

{{-- Project table --}}
<div class="box box-default">
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('admin/exhibit-projects/general.status') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.submitted_file') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.name') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.project_type') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.project_details') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.requested_device') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.assigned_asset') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.approved') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.peripherals') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.tdx_id') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($projects as $project)
                <tr>
                    <td><span class="label label-{{ $project->statusColor() }}">{{ trans('admin/exhibit-projects/general.status_value_'.$project->status) }}</span></td>
                    <td>@if ($project->submitted_file)<i class="fas fa-check text-success"></i>@endif</td>
                    <td>
                        @if ($project->user)
                            <a href="{{ route('users.show', $project->user) }}">{{ $project->user->full_name }}</a>
                        @else
                            {{ $project->student_name ?: '—' }}
                        @endif
                    </td>
                    <td>{{ $project->project_type ? trans('admin/exhibit-projects/general.type_value_'.$project->project_type) : '' }}</td>
                    <td>{{ $project->project_details }}</td>
                    <td>{{ $project->requested_device }}</td>
                    <td>
                        @if ($project->asset)
                            <a href="{{ route('hardware.show', $project->asset) }}">{{ $project->assignedDeviceLabel() }}</a>
                        @endif
                    </td>
                    <td>@if ($project->approved)<i class="fas fa-check text-success"></i>@endif</td>
                    <td>{{ $project->peripherals }}</td>
                    <td>{{ $project->tdx_id }}</td>
                    <td class="text-right">
                        <a href="{{ route('exhibit-projects.show', $project) }}" class="btn btn-xs btn-default" title="{{ trans('admin/exhibit-projects/general.send_email') }}"><i class="fas fa-envelope"></i></a>
                        <a href="{{ route('exhibit-projects.edit', $project) }}" class="btn btn-xs btn-warning"><i class="fas fa-pencil-alt"></i></a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="text-center text-muted">{{ trans('admin/exhibit-projects/general.no_projects') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@stop
