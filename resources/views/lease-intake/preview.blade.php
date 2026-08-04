@extends('layouts/default')

@section('title')
    {{ trans('admin/lease-intake/general.preview_title') }}
    @parent
@stop

@section('content')

@php
    $type = $parsed['type'];
    $isAgreement = $type === \App\Services\Leasing\LeaseDocumentParser::TYPE_SCHEDULE_AGREEMENT;
    $isAcceptance = $type === \App\Services\Leasing\LeaseDocumentParser::TYPE_CERTIFICATE_OF_ACCEPTANCE;
    $isExhibit = $type === \App\Services\Leasing\LeaseDocumentParser::TYPE_EXHIBIT_A_DRAFT;
@endphp

<div class="row">
    <div class="col-md-8">
        <form method="POST" action="{{ route('lease-documents.commit') }}" class="form-horizontal">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="original_name" value="{{ $original_name }}">

            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/lease-intake/general.'.['schedule_agreement' => 'type_schedule_agreement', 'certificate_of_acceptance' => 'type_certificate_of_acceptance', 'exhibit_a_draft' => 'type_exhibit_a_draft'][$type]) }}</h3>
                </div>
                <div class="box-body">
                    <p class="text-muted">{{ trans('admin/lease-intake/general.preview_intro') }}</p>
                    <p>
                        <strong>{{ trans('admin/lease-intake/general.document') }}:</strong>
                        {{ $original_name }}
                    </p>

                    <div class="form-group">
                        <label class="col-md-4 control-label" for="schedule_ref">{{ trans('admin/lease-intake/general.schedule_ref') }}</label>
                        <div class="col-md-5">
                            <input type="text" class="form-control" name="schedule_ref" id="schedule_ref" value="{{ $parsed['schedule_ref'] }}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-4 control-label" for="lessor">{{ trans('admin/lease-intake/general.lessor') }}</label>
                        <div class="col-md-5">
                            <input type="text" class="form-control" name="lessor" id="lessor" value="{{ $parsed['lessor'] ?? '' }}">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-4 control-label" for="dated_as_of">{{ trans('admin/lease-intake/general.dated_as_of') }}</label>
                        <div class="col-md-5">
                            <input type="date" class="form-control" name="dated_as_of" id="dated_as_of" value="{{ $parsed['dated_as_of'] ?? '' }}">
                        </div>
                    </div>

                    @unless ($isExhibit)
                        <div class="form-group">
                            <label class="col-md-4 control-label" for="term_start">{{ trans('admin/lease-intake/general.term_start') }}</label>
                            <div class="col-md-5">
                                <input type="date" class="form-control" name="term_start" id="term_start" value="{{ $parsed['term_start'] ?? '' }}">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-md-4 control-label" for="term_end">{{ trans('admin/lease-intake/general.term_end') }}</label>
                            <div class="col-md-5">
                                <input type="date" class="form-control" name="term_end" id="term_end" value="{{ $parsed['term_end'] ?? '' }}">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-md-4 control-label" for="term_months">{{ trans('admin/lease-intake/general.term_months') }}</label>
                            <div class="col-md-3">
                                <input type="number" class="form-control" name="term_months" id="term_months" value="{{ $parsed['term_months'] ?? '' }}" min="1" max="240">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-md-4 control-label" for="lease_type">{{ trans('admin/lease-intake/general.lease_type') }}</label>
                            <div class="col-md-5">
                                <select class="form-control" name="lease_type" id="lease_type">
                                    @foreach (['' => '', 'Lease to Return' => 'Lease to Return', 'Lease to Own' => 'Lease to Own'] as $value => $label)
                                        <option value="{{ $value }}" @selected(($parsed['lease_type'] ?? '') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endunless

                    @if ($isAcceptance)
                        <div class="form-group">
                            <label class="col-md-4 control-label" for="yearly_rental">{{ trans('admin/lease-intake/general.yearly_rental') }}</label>
                            <div class="col-md-3">
                                <input type="number" step="0.01" class="form-control" name="yearly_rental" id="yearly_rental" value="{{ $parsed['yearly_rental'] ?? '' }}">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-md-4 control-label" for="stip_loss_value">{{ trans('admin/lease-intake/general.stip_loss_value') }}</label>
                            <div class="col-md-3">
                                <input type="number" step="0.01" class="form-control" name="stip_loss_value" id="stip_loss_value" value="{{ $parsed['stip_loss_value'] ?? '' }}">
                            </div>
                        </div>
                    @endif

                    @if ($isAgreement)
                        <div class="form-group">
                            <label class="col-md-4 control-label" for="cost_cap">{{ trans('admin/lease-intake/general.cost_cap') }}</label>
                            <div class="col-md-3">
                                <input type="number" step="0.01" class="form-control" name="cost_cap" id="cost_cap" value="{{ $parsed['cost_cap'] ?? '' }}">
                            </div>
                        </div>
                    @endif

                    @if ($isExhibit && ! empty($parsed['totals']))
                        <table class="table table-condensed" style="max-width: 420px;">
                            @foreach (['total_rent', 'equipment_cost', 'soft_cost', 'total_cost'] as $key)
                                @if (isset($parsed['totals'][$key]))
                                    <tr>
                                        <th>{{ trans('admin/lease-intake/general.'.$key) }}</th>
                                        <td class="text-right">{{ number_format($parsed['totals'][$key], 2) }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </table>
                    @endif
                </div>
                <div class="box-footer">
                    <a href="{{ url()->previous() }}" class="btn btn-link">{{ trans('admin/lease-intake/general.cancel') }}</a>
                    <button type="submit" class="btn btn-primary pull-right">
                        <i class="fas fa-check icon-white" aria-hidden="true"></i>
                        {{ trans('admin/lease-intake/general.commit') }}
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="col-md-4">
        @if ($warnings)
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/lease-intake/general.warnings') }}</h3>
                </div>
                <div class="box-body">
                    <ul class="list-unstyled">
                        @foreach ($warnings as $warning)
                            <li style="margin-bottom: 8px;"><i class="fas fa-exclamation-triangle text-yellow" aria-hidden="true"></i> {{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>
</div>

@if (! empty($parsed['lines']))
    <div class="row">
        <div class="col-md-12">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/lease-intake/general.lines') }} ({{ count($parsed['lines']) }})</h3>
                </div>
                <div class="box-body table-responsive">
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>{{ trans('admin/lease-intake/general.line_serial') }}</th>
                                <th>{{ trans('admin/lease-intake/general.line_description') }}</th>
                                <th class="text-right">{{ trans('admin/lease-intake/general.line_rental') }}</th>
                                <th>{{ trans('admin/lease-intake/general.line_commencement') }}</th>
                                @if ($isExhibit)
                                    <th class="text-right">{{ trans('admin/lease-intake/general.line_cost') }}</th>
                                    <th>{{ trans('admin/lease-intake/general.line_invoices') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($parsed['lines'] as $line)
                                <tr>
                                    <td><code>{{ $line['serial'] }}</code></td>
                                    <td>{{ $line['description'] ?? '' }}</td>
                                    <td class="text-right">{{ isset($line['yearly_rental']) ? number_format($line['yearly_rental'], 2) : '' }}</td>
                                    <td>{{ $line['commencement'] ?? '' }}</td>
                                    @if ($isExhibit)
                                        <td class="text-right">{{ isset($line['total_cost']) ? number_format($line['total_cost'], 2) : '' }}</td>
                                        <td>{{ $line['invoice_numbers'] ?? '' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endif

@stop
