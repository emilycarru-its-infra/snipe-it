@extends('layouts/default')

@section('title')
    {{ trans('admin/forms/faculty-program.submissions_title') }}
    @parent
@stop

@section('content')

<div class="row">
    <div class="col-md-12">
        <h1 style="margin-top:0;">{{ trans('admin/forms/faculty-program.submissions_title') }}</h1>

        @if ($agreements->isEmpty())
            <div class="alert alert-info">{{ trans('admin/forms/faculty-program.submissions_empty') }}</div>
        @else
            <div class="box box-default">
                <div class="box-body table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>{{ trans('admin/forms/faculty-program.submission_user') }}</th>
                                <th>{{ trans('admin/forms/faculty-program.submission_type') }}</th>
                                <th>{{ trans('admin/forms/faculty-program.submission_stage') }}</th>
                                <th>{{ trans('admin/forms/faculty-program.submission_asset') }}</th>
                                <th>{{ trans('admin/forms/faculty-program.submission_payment') }}</th>
                                <th>{{ trans('admin/forms/faculty-program.submission_created') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($agreements as $agreement)
                                <tr>
                                    <td>
                                        <a href="{{ route('forms.submissions.show', ['faculty-program', $agreement->id]) }}">
                                            {{ $agreement->id }}
                                        </a>
                                    </td>
                                    <td>{{ $agreement->user?->present()?->fullName() ?? '—' }}</td>
                                    <td>{{ $agreement->agreement_type }}</td>
                                    <td>{{ $agreement->lifecycle_stage }}</td>
                                    <td>{{ $agreement->asset?->asset_tag ?? '—' }}</td>
                                    <td>{{ $agreement->payment_method ?? '—' }}</td>
                                    <td>{{ $agreement->created_at?->toDateString() ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="box-footer">
                    {{ $agreements->links() }}
                </div>
            </div>
        @endif
    </div>
</div>

@stop
