@extends('layouts/default')

@section('title')
    {{ trans('admin/forms/faculty-program.submission_show_title', ['id' => $agreement->id]) }}
    @parent
@stop

@section('content')

<div class="row">
    <div class="col-md-8 col-md-offset-2">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">
                    {{ trans('admin/forms/faculty-program.submission_show_title', ['id' => $agreement->id]) }}
                </h2>
            </div>
            <div class="box-body">
                <dl class="dl-horizontal">
                    <dt>{{ trans('admin/forms/faculty-program.submission_user') }}</dt>
                    <dd>{{ $agreement->user?->present()?->fullName() ?? '—' }}</dd>

                    <dt>{{ trans('admin/forms/faculty-program.submission_type') }}</dt>
                    <dd>{{ $agreement->agreement_type }}</dd>

                    <dt>{{ trans('admin/forms/faculty-program.submission_stage') }}</dt>
                    <dd>{{ $agreement->lifecycle_stage }}</dd>

                    <dt>{{ trans('admin/forms/faculty-program.submission_asset') }}</dt>
                    <dd>
                        @if ($agreement->asset)
                            <a href="{{ route('hardware.show', $agreement->asset->id) }}">
                                {{ $agreement->asset->asset_tag }}
                            </a>
                        @else
                            —
                        @endif
                    </dd>

                    <dt>{{ trans('admin/forms/faculty-program.submission_payment') }}</dt>
                    <dd>{{ $agreement->payment_method ?? '—' }}</dd>

                    <dt>{{ trans('admin/forms/faculty-program.submission_notes') }}</dt>
                    <dd>{{ $agreement->notes ?? '—' }}</dd>

                    <dt>{{ trans('admin/forms/faculty-program.submission_created') }}</dt>
                    <dd>{{ $agreement->created_at?->toDateTimeString() ?? '—' }}</dd>
                </dl>

                <div style="margin-top:20px;">
                    <a href="{{ route('user-agreements.pdf', $agreement->id) }}" class="btn btn-default" target="_blank" rel="noopener">
                        <i class="fas fa-file-pdf" aria-hidden="true"></i>
                        @if ($agreement->signed_pdf_path)
                            {{ trans('admin/forms/faculty-program.submission_pdf_signed') }}
                        @else
                            {{ trans('admin/forms/faculty-program.submission_pdf_preview') }}
                        @endif
                    </a>
                    <a href="{{ route('user-agreements.show', $agreement->id) }}" class="btn btn-link">
                        {{ trans('general.view') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

@stop
