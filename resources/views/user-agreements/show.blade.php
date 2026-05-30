@extends('layouts/default')

@section('title')
    {{ trans('admin/user-agreements/general.agreement') }} #{{ $agreement->id }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-7">
        <div class="alert alert-info">
            <strong>{{ trans('admin/user-agreements/general.user_agreements') }}.</strong>
            {{ trans('admin/user-agreements/general.native_signing_intro') }}
        </div>

        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">
                    {{ trans('admin/purchase-orders/general.user_agreement_type_value_'.$agreement->agreement_type) }} —
                    {{ $agreement->user?->full_name ?? trans('general.unknown') }}
                </h3>
                <div class="box-tools pull-right">
                    @if ($agreement->signed_at)
                        <span class="label label-success">{{ trans('admin/user-agreements/general.signed') }} {{ $agreement->signed_at->format('Y-m-d') }}</span>
                    @elseif ($agreement->lifecycle_stage === 'agreement_sent')
                        <span class="label label-warning">{{ trans('admin/user-agreements/general.awaiting_signature') }}</span>
                    @else
                        <span class="label label-default">{{ trans('admin/purchase-orders/general.user_agreement_stage_value_'.$agreement->lifecycle_stage) }}</span>
                    @endif
                </div>
            </div>
            <div class="box-body">
                <dl class="dl-horizontal">
                    <dt>{{ trans('admin/user-agreements/general.user_agreement_member') }}</dt>
                    <dd>{{ $agreement->user?->full_name ?? '—' }}</dd>
                    <dt>{{ trans('admin/user-agreements/general.asset') }}</dt>
                    <dd>{{ $agreement->asset ? $agreement->asset->asset_tag.' ('.$agreement->asset->serial.')' : '—' }}</dd>
                    <dt>{{ trans('admin/user-agreements/general.base_program_price') }}</dt>
                    <dd>${{ number_format((float) $agreement->base_program_price, 2) }}</dd>
                    <dt>{{ trans('admin/user-agreements/general.device_cost') }}</dt>
                    <dd>${{ number_format((float) $agreement->device_cost, 2) }}</dd>
                    <dt>{{ trans('admin/user-agreements/general.top_up_amount') }}</dt>
                    <dd>${{ number_format((float) $agreement->top_up_amount, 2) }}</dd>
                    <dt>{{ trans('admin/user-agreements/general.buyout_cost') }}</dt>
                    <dd>${{ number_format((float) $agreement->buyout_cost, 2) }}</dd>
                    <dt>{{ trans('admin/user-agreements/general.payment_method') }}</dt>
                    <dd>
                        @if ($agreement->payment_method)
                            {{ trans('admin/purchase-orders/general.user_agreement_payment_value_'.$agreement->payment_method) }}
                        @else — @endif
                    </dd>
                </dl>
            </div>
            <div class="box-footer">
                <a class="btn btn-warning" href="{{ route('user-agreements.edit', $agreement) }}">
                    <i class="fas fa-pencil-alt"></i> {{ trans('general.update') }}
                </a>
                <a class="btn btn-default" href="{{ route('user-agreements.pdf', $agreement) }}" target="_blank">
                    @if ($agreement->signed_pdf_path)
                        <i class="fas fa-download"></i> {{ trans('admin/user-agreements/general.download_signed_pdf') }}
                    @else
                        <i class="fas fa-file-pdf"></i> {{ trans('admin/user-agreements/general.preview_pdf') }}
                    @endif
                </a>
                @if (! $agreement->signed_pdf_path && $agreement->asset_id && $agreement->user_id)
                    <form method="POST" action="{{ route('user-agreements.pregen-pdf', $agreement) }}" style="display:inline-block;">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-default" title="{{ trans('admin/user-agreements/general.regenerate_pdf_help') }}">
                            <i class="fas fa-sync-alt"></i> {{ trans('admin/user-agreements/general.regenerate_pdf') }}
                        </button>
                    </form>
                @endif
                @if (! $agreement->checkout_acceptance_id && $agreement->asset_id && $agreement->user_id)
                    <form method="POST" action="{{ route('user-agreements.send-for-signature', $agreement) }}" class="pull-right" style="display:inline-block;">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> {{ trans('admin/user-agreements/general.send_for_signature') }}
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
                        {{ trans('admin/user-agreements/eula.pickup_title') }}
                    @elseif ($agreement->agreement_type === 'upgrade')
                        {{ trans('admin/user-agreements/eula.upgrade_title') }}
                    @elseif ($agreement->agreement_type === 'purchase')
                        {{ trans('admin/user-agreements/eula.purchase_title') }}
                    @endif
                </h3>
            </div>
            <div class="box-body" style="white-space: pre-wrap; font-family: var(--bs-font-monospace, monospace); font-size: 12px;">{{ $eulaPreview }}</div>
        </div>
    </div>
</div>
@stop
