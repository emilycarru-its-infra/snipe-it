@extends('layouts/default')

@section('title')
    {{ trans('admin/faculty-agreements/general.agreement') }} #{{ $agreement->id }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-7">
        <div class="alert alert-info">
            <strong>{{ trans('admin/faculty-agreements/general.faculty_agreements') }}.</strong>
            {{ trans('admin/faculty-agreements/general.native_signing_intro') }}
        </div>

        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">
                    {{ trans('admin/purchase-orders/general.faculty_type_'.$agreement->agreement_type) }} —
                    {{ $agreement->user?->full_name ?? trans('general.unknown') }}
                </h3>
                <div class="box-tools pull-right">
                    @if ($agreement->signed_at)
                        <span class="label label-success">{{ trans('admin/faculty-agreements/general.signed') }} {{ $agreement->signed_at->format('Y-m-d') }}</span>
                    @elseif ($agreement->lifecycle_stage === 'agreement_sent')
                        <span class="label label-warning">{{ trans('admin/faculty-agreements/general.awaiting_signature') }}</span>
                    @else
                        <span class="label label-default">{{ trans('admin/purchase-orders/general.faculty_stage_'.$agreement->lifecycle_stage) }}</span>
                    @endif
                </div>
            </div>
            <div class="box-body">
                <dl class="dl-horizontal">
                    <dt>{{ trans('admin/faculty-agreements/general.faculty_member') }}</dt>
                    <dd>{{ $agreement->user?->full_name ?? '—' }}</dd>
                    <dt>{{ trans('admin/faculty-agreements/general.asset') }}</dt>
                    <dd>{{ $agreement->asset ? $agreement->asset->asset_tag.' ('.$agreement->asset->serial.')' : '—' }}</dd>
                    <dt>{{ trans('admin/faculty-agreements/general.base_program_price') }}</dt>
                    <dd>${{ number_format((float) $agreement->base_program_price, 2) }}</dd>
                    <dt>{{ trans('admin/faculty-agreements/general.device_cost') }}</dt>
                    <dd>${{ number_format((float) $agreement->device_cost, 2) }}</dd>
                    <dt>{{ trans('admin/faculty-agreements/general.top_up_amount') }}</dt>
                    <dd>${{ number_format((float) $agreement->top_up_amount, 2) }}</dd>
                    <dt>{{ trans('admin/faculty-agreements/general.buyout_cost') }}</dt>
                    <dd>${{ number_format((float) $agreement->buyout_cost, 2) }}</dd>
                    <dt>{{ trans('admin/faculty-agreements/general.payment_method') }}</dt>
                    <dd>
                        @if ($agreement->payment_method)
                            {{ trans('admin/purchase-orders/general.faculty_payment_'.$agreement->payment_method) }}
                        @else — @endif
                    </dd>
                </dl>
            </div>
            <div class="box-footer">
                <a class="btn btn-warning" href="{{ route('faculty-agreements.edit', $agreement) }}">
                    <i class="fas fa-pencil-alt"></i> {{ trans('general.update') }}
                </a>
                <a class="btn btn-default" href="{{ route('faculty-agreements.pdf', $agreement) }}" target="_blank">
                    @if ($agreement->signed_pdf_path)
                        <i class="fas fa-download"></i> {{ trans('admin/faculty-agreements/general.download_signed_pdf') }}
                    @else
                        <i class="fas fa-file-pdf"></i> {{ trans('admin/faculty-agreements/general.preview_pdf') }}
                    @endif
                </a>
                @if (! $agreement->checkout_acceptance_id && $agreement->asset_id && $agreement->user_id)
                    <form method="POST" action="{{ route('faculty-agreements.send-for-signature', $agreement) }}" class="pull-right" style="display:inline-block;">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> {{ trans('admin/faculty-agreements/general.send_for_signature') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">
                    @if ($agreement->agreement_type === 'pickup')
                        {{ trans('admin/faculty-agreements/eula.pickup_title') }}
                    @elseif ($agreement->agreement_type === 'upgrade')
                        {{ trans('admin/faculty-agreements/eula.upgrade_title') }}
                    @elseif ($agreement->agreement_type === 'lease_end_purchase')
                        {{ trans('admin/faculty-agreements/eula.lease_end_title') }}
                    @endif
                </h3>
            </div>
            <div class="box-body" style="white-space: pre-wrap; font-family: var(--bs-font-monospace, monospace); font-size: 12px;">{{ $eulaPreview }}</div>
        </div>
    </div>
</div>
@stop
