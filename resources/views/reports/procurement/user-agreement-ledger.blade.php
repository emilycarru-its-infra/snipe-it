@extends('layouts/default')

@php
    use App\Models\UserAgreement;
    use App\Models\Contract;

    $contractLookup = Contract::query()
        ->whereIn('contract_number', $agreements->pluck('lease_contract')->filter()->unique())
        ->orWhereIn('name', $agreements->pluck('lease_contract')->filter()->unique())
        ->get()
        ->mapWithKeys(fn ($c) => [
            (string) $c->contract_number => $c,
            (string) $c->name            => $c,
        ]);

    $stageLabelClass = UserAgreement::STAGE_LABEL_CLASS;
@endphp

@section('title')
    {{ $reportTitle }} @parent
@stop

@section('header_right')
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
    <a href="{{ route('reports.procurement') }}" class="btn btn-sm btn-default">
        {{ trans('admin/purchase-orders/general.reports') }}
    </a>
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <form method="GET" action="{{ route('reports.procurement.user-agreement-ledger') }}" class="form-inline" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                    <div class="form-group">
                        <label for="filter-type" style="display:block;">{{ trans('admin/user-agreements/general.filter_type') }}</label>
                        <select id="filter-type" name="agreement_type" class="form-control">
                            <option value="">{{ trans('admin/user-agreements/general.filter_all_types') }}</option>
                            @foreach (UserAgreement::AGREEMENT_TYPES as $t)
                                <option value="{{ $t }}" @selected($typeFilter === $t)>
                                    {{ trans('admin/purchase-orders/general.user_agreement_type_value_'.$t) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="filter-stage" style="display:block;">{{ trans('admin/user-agreements/general.filter_stage') }}</label>
                        <select id="filter-stage" name="stage" class="form-control">
                            <option value="">{{ trans('admin/user-agreements/general.filter_all_stages') }}</option>
                            @foreach (UserAgreement::LIFECYCLE_STAGES as $s)
                                <option value="{{ $s }}" @selected($stageFilter === $s)>
                                    {{ trans('admin/purchase-orders/general.user_agreement_stage_value_'.$s) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="filter-fy" style="display:block;">{{ trans('admin/purchase-orders/general.fiscal_year') }}</label>
                        <select id="filter-fy" name="fiscal_year" class="form-control">
                            <option value="all" {{ ($selectedFy ?? null) === null ? 'selected' : '' }}>{{ trans('admin/purchase-orders/general.all_fiscal_years') }}</option>
                            @foreach (($allFiscalYears ?? collect()) as $fyOption)
                                <option value="{{ $fyOption }}" {{ ($selectedFy ?? null) === $fyOption ? 'selected' : '' }}>{{ $fyOption }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">{{ trans('admin/user-agreements/general.apply_filters') }}</button>
                        <a href="{{ route('reports.procurement.user-agreement-ledger') }}" class="btn btn-default">{{ trans('admin/user-agreements/general.reset_filters') }}</a>
                    </div>
                </form>
            </div>

            <div class="box-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>{{ trans('admin/purchase-orders/general.user_agreement_type') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.user_agreement_member') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.detail_asset_tag') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.detail_serial') }}</th>
                                <th>{{ trans('admin/user-agreements/general.originating_contract') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.user_agreement_contract_value') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.user_agreement_stage') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.user_agreement_signed_at') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.user_agreement_payroll_at') }}</th>
                                <th class="text-right">{{ trans('admin/user-agreements/general.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($agreements as $agreement)
                            @php
                                $contract = $agreement->lease_contract ? ($contractLookup[$agreement->lease_contract] ?? null) : null;
                                $stageClass = $stageLabelClass[$agreement->lifecycle_stage] ?? 'default';
                                $isCancelled = $agreement->lifecycle_stage === 'cancelled';
                                $isSigned = (bool) ($agreement->signed_at || $agreement->signed_pdf_path);
                            @endphp
                            <tr @class(['text-muted' => $isCancelled])>
                                <td>{{ trans('admin/purchase-orders/general.user_agreement_type_value_'.$agreement->agreement_type) }}</td>
                                <td>
                                    <a href="{{ route('user-agreements.show', $agreement) }}">
                                        {{ $agreement->user?->full_name ?? '—' }}
                                    </a>
                                </td>
                                <td>
                                    @if ($agreement->asset)
                                        <a href="{{ route('hardware.show', $agreement->asset->id) }}">{{ $agreement->asset->asset_tag }}</a>
                                    @endif
                                </td>
                                <td>{{ $agreement->asset?->serial }}</td>
                                <td>
                                    @if ($contract)
                                        <a href="{{ route('contracts.show', $contract->id) }}">{{ $agreement->lease_contract }}</a>
                                    @else
                                        {{ $agreement->lease_contract }}
                                    @endif
                                </td>
                                <td>${{ number_format($agreement->contractValue(), 2) }}</td>
                                <td>
                                    <span class="label label-{{ $stageClass }}">
                                        {{ trans('admin/purchase-orders/general.user_agreement_stage_value_'.$agreement->lifecycle_stage) }}
                                    </span>
                                </td>
                                <td>{{ $agreement->signed_at?->format('Y-m-d') }}</td>
                                <td>{{ $agreement->sent_to_payroll_at?->format('Y-m-d') }}</td>
                                <td class="text-right" style="white-space:nowrap;">
                                    @if (! $isCancelled)
                                        <form method="POST" action="{{ route('user-agreements.regenerate', $agreement) }}" style="display:inline-block;">
                                            {{ csrf_field() }}
                                            <button type="submit" class="btn btn-xs btn-default" title="{{ trans('admin/user-agreements/general.row_action_regen') }}">
                                                <i class="fas fa-sync-alt"></i>
                                            </button>
                                        </form>
                                        @if (! $agreement->checkout_acceptance_id && $agreement->asset_id && $agreement->user_id && ! $isSigned)
                                            <form method="POST" action="{{ route('user-agreements.send-for-signature', $agreement) }}" style="display:inline-block;">
                                                {{ csrf_field() }}
                                                <button type="submit" class="btn btn-xs btn-primary" title="{{ trans('admin/user-agreements/general.row_action_send') }}">
                                                    <i class="fas fa-paper-plane"></i>
                                                </button>
                                            </form>
                                        @endif
                                        @if ($isSigned && ! $agreement->sent_to_payroll_at)
                                            <button type="button" class="btn btn-xs btn-success" title="{{ trans('admin/user-agreements/general.send_to_payroll') }}"
                                                    data-toggle="modal" data-target="#sendPayrollModal-{{ $agreement->id }}">
                                                <i class="fas fa-paperclip"></i>
                                            </button>
                                        @endif
                                        <button type="button" class="btn btn-xs btn-danger" title="{{ trans('admin/user-agreements/general.cancel') }}"
                                                data-toggle="modal" data-target="#cancelModal-{{ $agreement->id }}">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    @else
                                        <span class="text-muted small">
                                            @if ($agreement->cancellation_reason)
                                                {{ Str::limit($agreement->cancellation_reason, 50) }}
                                            @endif
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10">{{ trans('admin/user-agreements/general.no_agreements_match') }}</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Per-row modals: Cancel + Send to Payroll. Snipe-IT bundles Bootstrap 3 modals via AdminLTE. --}}
@foreach ($agreements as $agreement)
    @if ($agreement->lifecycle_stage !== 'cancelled')
        <div class="modal fade" id="cancelModal-{{ $agreement->id }}" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <form method="POST" action="{{ route('user-agreements.cancel', $agreement) }}">
                    {{ csrf_field() }}
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">{{ trans('admin/user-agreements/general.cancel_confirm_title') }}</h4>
                        </div>
                        <div class="modal-body">
                            <p>{{ trans('admin/user-agreements/general.cancel_confirm_body') }}</p>
                            <p>
                                <strong>{{ trans('admin/purchase-orders/general.user_agreement_type_value_'.$agreement->agreement_type) }}</strong>
                                &mdash; {{ $agreement->user?->full_name ?? '—' }}
                                @if ($agreement->asset) ({{ $agreement->asset->asset_tag }}) @endif
                            </p>
                            <div class="form-group">
                                <label for="cancel-reason-{{ $agreement->id }}">{{ trans('admin/user-agreements/general.cancellation_reason') }}</label>
                                <textarea id="cancel-reason-{{ $agreement->id }}" name="cancellation_reason" class="form-control" rows="2" maxlength="500"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('admin/user-agreements/general.keep_agreement') }}</button>
                            <button type="submit" class="btn btn-danger">{{ trans('admin/user-agreements/general.confirm_cancel') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        @if (($agreement->signed_at || $agreement->signed_pdf_path) && ! $agreement->sent_to_payroll_at)
            <div class="modal fade" id="sendPayrollModal-{{ $agreement->id }}" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <form method="POST" action="{{ route('user-agreements.send-to-payroll', $agreement) }}">
                        {{ csrf_field() }}
                        <div class="modal-content">
                            <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                <h4 class="modal-title">{{ trans('admin/user-agreements/general.send_to_payroll_confirm_title') }}</h4>
                            </div>
                            <div class="modal-body">
                                <p>{{ trans('admin/user-agreements/general.send_to_payroll_confirm_body') }}</p>
                                <p>
                                    <strong>{{ trans('admin/purchase-orders/general.user_agreement_type_value_'.$agreement->agreement_type) }}</strong>
                                    &mdash; {{ $agreement->user?->full_name ?? '—' }}
                                </p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('button.cancel') }}</button>
                                <button type="submit" class="btn btn-success">{{ trans('admin/user-agreements/general.confirm_send_to_payroll') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endif
@endforeach
@stop
