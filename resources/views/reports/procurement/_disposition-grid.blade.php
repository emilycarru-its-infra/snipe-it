{{-- Per-Serial Disposition Grid — one tab per lease contract (mirrors the
     sheets of the Leases.xlsx workbook). Per-serial rows with an editable
     disposition decision and note. No inline <script> here: the dashboard
     injects this partial via innerHTML (which strips scripts), so the save
     behaviour is wired through the document-level delegated handler in
     reports/procurement/_disposition-grid-js.blade.php, included on both the
     dashboard and the standalone page. --}}
<div class="disp-grid"
     data-decision-url="{{ route('reports.procurement.disposition-grid.decision') }}"
     data-csrf="{{ csrf_token() }}"
     data-can-edit="{{ ! empty($canEdit) ? '1' : '0' }}">
@if (empty($contracts))
    <p class="text-muted">{{ trans('admin/purchase-orders/general.disposition_none_leased') }}</p>
@else
    <ul class="nav nav-tabs disp-tabs" role="tablist">
        @foreach ($contracts as $i => $c)
            @php $paneId = 'disp-pane-'.$i; @endphp
            <li class="{{ $i === 0 ? 'active' : '' }}">
                <a href="#{{ $paneId }}" data-toggle="tab" role="tab">
                    {{ $c['contract_id'] }}
                    <span class="badge">{{ count($c['assets']) }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="tab-content disp-tab-content">
        @foreach ($contracts as $i => $c)
            @php $paneId = 'disp-pane-'.$i; @endphp
            <div class="tab-pane {{ $i === 0 ? 'active' : '' }}" id="{{ $paneId }}" role="tabpanel">
                <p class="text-muted disp-contract-meta">
                    <strong>{{ $c['provider'] }}</strong>
                    @if (! empty($c['lease_end_date']))
                        &middot; {{ trans('admin/purchase-orders/general.disposition_contract_ends', ['date' => $c['lease_end_date']]) }}
                    @endif
                    @if (! empty($c['is_lease_to_own']))
                        &middot; <span class="label label-default">{{ trans('admin/purchase-orders/general.disposition_retained') }}</span>
                    @endif
                </p>
                <div class="table-responsive">
                    <table class="table table-striped table-condensed disp-table">
                        <thead>
                            <tr>
                                <th>{{ trans('admin/purchase-orders/general.detail_serial') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.detail_asset_tag') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.detail_status') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.disposition_returned_date') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.invoice_usage') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.detail_ownership') }}</th>
                                <th>{{ trans('general.category') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.detail_model') }}</th>
                                <th class="text-right">{{ trans('admin/purchase-orders/general.detail_buyout_cost') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.disposition_action') }}</th>
                                <th>{{ trans('general.notes') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($c['assets'] as $a)
                                <tr data-asset-id="{{ $a['asset_id'] }}" data-contract="{{ $c['contract_id'] }}">
                                    <td>{{ $a['serial'] }}</td>
                                    <td>{{ $a['asset_tag'] }}</td>
                                    <td>{{ $a['status'] }}</td>
                                    <td>{{ $a['returned_date'] }}</td>
                                    <td>{{ $a['usage'] }}</td>
                                    <td>{{ $a['ownership'] }}</td>
                                    <td>{{ $a['category'] }}</td>
                                    <td>{{ $a['model'] }}</td>
                                    <td class="text-right">{{ $a['buyout_cost'] }}</td>
                                    <td class="disp-decision-cell">
                                        @if ($a['ownership'] === 'Lease to Own')
                                            <span class="label label-default">{{ trans('admin/purchase-orders/general.disposition_retained') }}</span>
                                        @elseif (! empty($canEdit))
                                            <select class="form-control input-sm disp-decision-select">
                                                <option value="">{{ trans('admin/purchase-orders/general.disposition_none') }}</option>
                                                @foreach ($decisionTypes as $type)
                                                    <option value="{{ $type }}" {{ $a['decision_type'] === $type ? 'selected' : '' }}>
                                                        {{ trans('admin/lease-decisions/general.type_'.$type) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <span class="disp-decision-status text-muted">
                                                @if ($a['decision_status'])
                                                    {{ trans('admin/lease-decisions/general.status_'.$a['decision_status']) }}
                                                @endif
                                                @if ($a['decision_scope'] === 'contract')
                                                    <em>({{ trans('admin/purchase-orders/general.disposition_inherited') }})</em>
                                                @endif
                                            </span>
                                        @else
                                            @if ($a['decision_type'])
                                                {{ trans('admin/lease-decisions/general.type_'.$a['decision_type']) }}
                                                @if ($a['decision_status'])
                                                    <span class="text-muted">&middot; {{ trans('admin/lease-decisions/general.status_'.$a['decision_status']) }}</span>
                                                @endif
                                            @else
                                                <span class="text-muted">{{ trans('admin/purchase-orders/general.disposition_none') }}</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="disp-note-cell">
                                        <span class="disp-note-text">{{ $a['decision_note'] }}</span>
                                        @if (! empty($canEdit) && $a['ownership'] !== 'Lease to Own')
                                            <a href="#" class="disp-note-edit" title="{{ trans('admin/purchase-orders/general.disposition_edit_note') }}">
                                                <i class="fa-solid fa-pencil" aria-hidden="true"></i>
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
@endif
</div>
