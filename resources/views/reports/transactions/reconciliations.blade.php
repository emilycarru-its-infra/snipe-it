@extends('layouts/default')

@section('title')
    {{ trans('admin/reports/transactions.tab_reconciliations') }}
    @parent
@stop

@section('content')

<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/reports/transactions.tab_reconciliations') }}</h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover">
            <thead><tr>
                <th>{{ trans('admin/reports/transactions.col_period') }}</th>
                <th>{{ trans('admin/reports/transactions.col_generated') }}</th>
                <th>{{ trans('admin/reports/transactions.col_status') }}</th>
                <th>{{ trans('admin/reports/transactions.col_workbook') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($items as $r)
                <tr>
                    <td><a href="{{ route('reports.transactions.show', ['ym' => $r->period_label]) }}">{{ $r->period_label }}</a></td>
                    <td>{{ $r->generated_at?->toDayDateTimeString() }}</td>
                    <td><span class="label label-{{ $r->status === 'published' ? 'success' : 'warning' }}">{{ $r->status }}</span></td>
                    <td>
                        @if ($r->sharepoint_url)
                            <a href="{{ $r->sharepoint_url }}" target="_blank">SharePoint</a>
                        @endif
                        @if ($r->workbook_blob_url)
                            @if ($r->sharepoint_url) &middot; @endif
                            <a href="{{ $r->workbook_blob_url }}" target="_blank">Blob</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted">{{ trans('admin/reports/transactions.empty_reconciliations') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="box-footer">{{ $items->links() }}</div>
</div>

@stop
