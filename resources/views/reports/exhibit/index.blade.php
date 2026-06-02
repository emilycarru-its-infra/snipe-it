@extends('layouts/default')

@section('title')
    {{ trans('admin/exhibit-projects/general.dashboard_title') }} @parent
@stop

@section('header_right')
    <a href="{{ route('exhibit-config.index', 'exhibits') }}" class="btn btn-sm btn-default"><i class="fas fa-cog"></i> {{ trans('admin/exhibit-projects/general.configure') }}</a>
    <a href="{{ route('exhibit-email-templates.index') }}" class="btn btn-sm btn-default"><i class="fas fa-envelope"></i> {{ trans('admin/exhibit-projects/general.email_templates') }}</a>
    <a href="{{ route('exhibit-projects.import-form') }}" class="btn btn-sm btn-default"><i class="fas fa-upload"></i> {{ trans('admin/exhibit-projects/general.import_title') }}</a>
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default"><i class="fas fa-download"></i> {{ trans('general.download') }}</a>
    <a href="{{ route('exhibit-projects.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> {{ trans('admin/exhibit-projects/general.add_project') }}</a>
@stop

@section('content')

{{-- Filters --}}
<div class="row">
    <div class="col-md-12">
        <form method="GET" action="{{ route('reports.exhibit') }}" class="form-inline" style="margin-bottom:15px;">
            <div class="form-group">
                <label>{{ trans('admin/exhibit-projects/general.filter_show') }}</label>
                <select name="exhibit" class="form-control" onchange="this.form.submit()">
                    @foreach ($exhibits as $ex)
                        <option value="{{ $ex->id }}" {{ (int) $exhibitId === (int) $ex->id ? 'selected' : '' }}>{{ $ex->name }}</option>
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
                    @foreach ($statuses as $st)
                        <option value="{{ $st->id }}" {{ (string) $statusFilter === (string) $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>
</div>

{{-- Donut + count widgets --}}
<div class="row">
    @php($cards = [
        ['key' => 'type', 'title' => trans('admin/exhibit-projects/general.widget_project_type'), 'canvas' => 'exhibitTypeChart'],
        ['key' => 'status', 'title' => trans('admin/exhibit-projects/general.widget_status'), 'canvas' => 'exhibitStatusChart'],
        ['key' => 'device', 'title' => trans('admin/exhibit-projects/general.widget_device'), 'canvas' => 'exhibitDeviceChart'],
    ])
    @foreach ($cards as $card)
        <div class="col-md-4">
            <div class="box box-default">
                <div class="box-header with-border"><h3 class="box-title">{{ $card['title'] }}</h3></div>
                <div class="box-body">
                    <div style="position:relative; height:200px; margin-bottom:10px;">
                        <canvas id="{{ $card['canvas'] }}"></canvas>
                    </div>
                    <table class="table table-striped" style="margin-bottom:0;">
                        <tbody>
                        @forelse ($widgets[$card['key']]['rows'] as $r)
                            <tr>
                                <td><span class="label" style="background-color: {{ $r['color'] }}; color:#fff;">{{ $r['label'] }}</span></td>
                                <td class="text-right"><strong>{{ $r['count'] }}</strong></td>
                                <td class="text-right text-muted">{{ $r['pct'] }}%</td>
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
            <input type="hidden" name="exhibit" value="{{ $exhibitId }}">
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
                    <td><span class="label" style="background-color: {{ $project->statusColor() }}; color:#fff;">{{ $project->statusLabel() }}</span></td>
                    <td>@if ($project->submitted_file)<i class="fas fa-check text-success"></i>@endif</td>
                    <td>
                        @if ($project->user)
                            <a href="{{ route('users.show', $project->user) }}">{{ $project->user->full_name }}</a>
                        @else
                            {{ $project->student_name ?: '—' }}
                        @endif
                    </td>
                    <td>{{ $project->typeLabel() }}</td>
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

<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
(function () {
    var donut = function (id, payload) {
        var el = document.getElementById(id);
        if (!el || !payload.labels.length) { return; }
        new Chart(el, {
            type: 'doughnut',
            data: {
                labels: payload.labels,
                datasets: [{ data: payload.data, backgroundColor: payload.colors }]
            },
            options: { responsive: true, maintainAspectRatio: false, legend: { position: 'right' } }
        });
    };
    donut('exhibitTypeChart', @json($widgets['type']['chart']));
    donut('exhibitStatusChart', @json($widgets['status']['chart']));
    donut('exhibitDeviceChart', @json($widgets['device']['chart']));
})();
</script>
@stop
