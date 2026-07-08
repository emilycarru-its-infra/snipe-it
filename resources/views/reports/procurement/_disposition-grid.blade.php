{{-- Disposition Grid — a contract dropdown selects one lease pane (mirrors the
     sheets of the Leases workbook). One row per leased serial. The disposition
     is NOT entered here: it is read from each device's own Inventory status +
     Decommissioned Date (an archived status with a decommission date = the
     device has left our management). The only editable field is a free-text
     note per device (buyout justifications / special cases). No inline <script>
     here: the dashboard injects this partial via innerHTML (which strips
     scripts), so note saving is wired through the document-level delegated
     handler in _disposition-grid-js.blade.php (included on both pages). --}}
<div class="disp-grid"
     data-note-url="{{ route('reports.procurement.disposition-grid.note') }}"
     data-csrf="{{ csrf_token() }}"
     data-can-edit="{{ ! empty($canEdit) ? '1' : '0' }}">
@if (empty($contracts))
    <p class="text-muted">{{ trans('admin/purchase-orders/general.disposition_none_leased') }}</p>
@else
    {{-- Serial search (from #244): jumps the dropdown to the matching contract
         and highlights the row. --}}
    <div class="disp-search-bar">
        <div class="input-group input-group-sm disp-search-group">
            <span class="input-group-addon"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
            <input type="text" class="form-control disp-search" autocomplete="off" spellcheck="false"
                   placeholder="{{ trans('admin/purchase-orders/general.disposition_search_serial') }}">
            <span class="input-group-addon disp-search-clear" role="button" title="{{ trans('button.delete') }}" style="display:none;">&times;</span>
        </div>
        <span class="disp-search-status text-muted" aria-live="polite"></span>
    </div>
    <div class="form-group disp-contract-picker">
        <label for="disp-contract-select" class="disp-contract-label">{{ trans('admin/purchase-orders/general.disposition_pick_contract') }}</label>
        <select id="disp-contract-select" class="form-control input-sm disp-contract-select">
            @foreach ($contracts as $i => $c)
                <option value="disp-pane-{{ $i }}" {{ $i === 0 ? 'selected' : '' }}>
                    {{ $c['contract_id'] }} — {{ $c['active_count'] }}/{{ count($c['assets']) }}{{ ! empty($c['provider']) ? ' · '.$c['provider'] : '' }}
                </option>
            @endforeach
        </select>
    </div>


    <div class="tab-content disp-tab-content">
        @foreach ($contracts as $i => $c)
            @php $paneId = 'disp-pane-'.$i; @endphp
            <div class="tab-pane {{ $i === 0 ? 'active' : '' }}" id="{{ $paneId }}" role="tabpanel">
                <p class="text-muted disp-contract-meta">
                    <strong>{{ $c['provider'] }}</strong>
                    @if (! empty($c['lease_end_date']))
                        &middot; {{ trans('admin/purchase-orders/general.disposition_contract_ends', ['date' => $c['lease_end_date']]) }}
                    @endif
                    &middot; {{ trans('admin/purchase-orders/general.disposition_on_lease_count', ['active' => $c['active_count'], 'total' => count($c['assets'])]) }}
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
                                <th>{{ trans('admin/purchase-orders/general.disposition_action') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.disposition_decommissioned_date') }}</th>
                                <th class="text-right">{{ trans('admin/purchase-orders/general.detail_buyout_cost') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.disposition_use') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.detail_ownership') }}</th>
                                <th>{{ trans('general.category') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.detail_model') }}</th>
                                <th>{{ trans('general.notes') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($c['assets'] as $a)
                                <tr data-asset-id="{{ $a['asset_id'] }}" data-contract="{{ $c['contract_id'] }}" data-serial="{{ $a['serial'] }}" data-tag="{{ $a['asset_tag'] }}" data-pane="disp-pane-{{ $i }}" @if ($a['archived']) class="text-muted disp-archived" @endif>
                                    <td>{{ $a['serial'] }}</td>
                                    <td>{{ $a['asset_tag'] }}</td>
                                    <td>
                                        @php
                                            $statusClass = match ($a['status_type'] ?? null) {
                                                'deployable' => 'label-success',
                                                'pending' => 'label-warning',
                                                'undeployable' => 'label-danger',
                                                default => 'label-default',
                                            };
                                        @endphp
                                        <span class="label {{ $statusClass }}">{{ $a['status'] }}</span>
                                    </td>
                                    <td>{{ $a['decommissioned_date'] }}</td>
                                    <td class="text-right">{{ $a['buyout_cost'] }}</td>
                                    <td>{{ $a['use'] }}</td>
                                    <td>{{ $a['ownership'] }}</td>
                                    <td>{{ $a['category'] }}</td>
                                    <td>{{ $a['model'] }}</td>
                                    <td class="disp-note-cell">
                                        <span class="disp-note-text">{{ $a['note'] }}</span>
                                        @if (! empty($canEdit))
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
