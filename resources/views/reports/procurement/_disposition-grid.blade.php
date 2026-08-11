{{-- Disposition Grid — a contract dropdown selects one lease pane (mirrors the
     sheets of the Leases workbook). One row per leased serial. The disposition
     is read from each device's own Inventory status + Decommissioned Date (an
     archived status with a decommission date = the device has left our
     management). With asset-update permission the status, decommissioned date
     and buyout cost are editable inline (pencil) and in bulk (row checkboxes +
     the apply bar); the free-text note stays per-device. No inline <script>
     here: the dashboard injects this partial via innerHTML (which strips
     scripts), so all behaviour is wired through the document-level delegated
     handlers in _disposition-grid-js.blade.php (included on both pages). --}}
<div class="disp-grid"
     data-note-url="{{ route('reports.procurement.disposition-grid.note') }}"
     data-update-url="{{ route('reports.procurement.disposition-grid.update') }}"
     data-csrf="{{ csrf_token() }}"
     data-can-edit="{{ ! empty($canEdit) ? '1' : '0' }}"
     data-can-edit-assets="{{ ! empty($canEditAssets) ? '1' : '0' }}">
@if (empty($contracts))
    <p class="text-muted">{{ trans('admin/purchase-orders/general.disposition_none_leased') }}</p>
@else
    @php
        $selectedContract = $selectedContract ?? ($contracts[0]['contract_id'] ?? '');
        $canEditAssets = ! empty($canEditAssets);
        $statusOptions = $statusOptions ?? collect();
    @endphp
    {{-- Serial search (from #244): jumps the dropdown to the matching contract
         and highlights the row. --}}
    <div class="disp-search-bar">
        <div class="input-group input-group-sm disp-search-group disp-search-empty">
            <span class="input-group-addon"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
            <input type="text" class="form-control disp-search" autocomplete="off" spellcheck="false"
                   placeholder="{{ trans('admin/purchase-orders/general.disposition_search_serial') }}">
            <span class="input-group-addon disp-search-clear" role="button" title="{{ trans('button.delete') }}" style="display:none;">&times;</span>
        </div>
        <span class="disp-search-status text-muted" aria-live="polite"></span>
    </div>
    <div class="form-group disp-contract-picker">
        <label for="disp-contract-select" class="disp-contract-label">{{ trans('admin/purchase-orders/general.disposition_pick_contract') }}</label>
        {{-- The native select stays as the source of truth (pane switching and
             the serial search both drive it); the combo below is a presentation
             layer over it, because a <select> cannot align its options into
             columns and a run of "name — id — count · lessor" is unscannable
             at 40+ leases. --}}
        <select id="disp-contract-select" class="form-control input-sm disp-contract-select disp-contract-select-native">
            @foreach ($contracts as $i => $c)
                <option value="disp-pane-{{ $i }}" data-contract="{{ $c['contract_id'] }}" {{ $c['contract_id'] === $selectedContract ? 'selected' : '' }}>
                    {{ ! empty($c['contract_name']) ? $c['contract_name'].' — ' : '' }}{{ $c['contract_id'] }} — {{ $c['active_count'] }}/{{ count($c['assets']) }}{{ ! empty($c['provider']) ? ' · '.$c['provider'] : '' }}
                </option>
            @endforeach
        </select>
        @php
            // Rendered server-side so a lazily-injected grid (dashboard embed,
            // where inline scripts are stripped) shows its selection before any
            // JS runs.
            $selected = collect($contracts)->firstWhere('contract_id', $selectedContract) ?: ($contracts[0] ?? null);
            $selectedLabel = $selected ? implode(' · ', array_filter([
                $selected['contract_name'] ?: null,
                $selected['contract_id'],
                $selected['active_count'].'/'.count($selected['assets']),
                $selected['provider'] ?: null,
            ])) : '';
        @endphp
        <div class="disp-combo">
            <button type="button" class="disp-combo-button" aria-haspopup="listbox" aria-expanded="false">
                <span class="disp-combo-current">{{ $selectedLabel }}</span>
                <i class="fa-solid fa-angle-down disp-combo-caret" aria-hidden="true"></i>
            </button>
            <div class="disp-combo-panel" role="listbox" hidden>
                <div class="disp-combo-filter">
                    <input type="text" class="form-control input-sm disp-combo-search" autocomplete="off" spellcheck="false"
                           placeholder="{{ trans('admin/purchase-orders/general.disposition_filter_contracts') }}">
                </div>
                <div class="disp-combo-head" aria-hidden="true">
                    <span>{{ trans('admin/purchase-orders/general.disposition_pick_contract') }}</span>
                    <span>{{ trans('admin/purchase-orders/general.lease_contract_id') }}</span>
                    <span class="disp-combo-num">{{ trans('admin/purchase-orders/general.disposition_on_lease') }}</span>
                    <span>{{ trans('admin/purchase-orders/general.disposition_lessor') }}</span>
                </div>
                <div class="disp-combo-list">
                    @foreach ($contracts as $i => $c)
                        <div class="disp-combo-option{{ $c['contract_id'] === $selectedContract ? ' is-selected' : '' }}"
                             role="option" tabindex="-1"
                             aria-selected="{{ $c['contract_id'] === $selectedContract ? 'true' : 'false' }}"
                             data-value="disp-pane-{{ $i }}">
                            <span class="disp-combo-name">{{ $c['contract_name'] ?: '—' }}</span>
                            <span class="disp-combo-id">{{ $c['contract_id'] }}</span>
                            <span class="disp-combo-num">{{ $c['active_count'] }}/{{ count($c['assets']) }}</span>
                            <span class="disp-combo-lessor">{{ $c['provider'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="tab-content disp-tab-content">
        @foreach ($contracts as $i => $c)
            @php $paneId = 'disp-pane-'.$i; @endphp
            <div class="tab-pane {{ $c['contract_id'] === $selectedContract ? 'active' : '' }}" id="{{ $paneId }}" role="tabpanel">
                <p class="text-muted disp-contract-meta">
                    @if (! empty($c['contract_name']))
                        <strong>{{ $c['contract_name'] }}</strong> &middot; {{ $c['contract_id'] }} &middot; {{ $c['provider'] }}
                    @else
                        <strong>{{ $c['provider'] }}</strong>
                    @endif
                    @if (! empty($c['lease_end_date']))
                        &middot; {{ trans('admin/purchase-orders/general.disposition_contract_ends', ['date' => $c['lease_end_date']]) }}
                    @endif
                    &middot; {{ trans('admin/purchase-orders/general.disposition_on_lease_count', ['active' => $c['active_count'], 'total' => count($c['assets'])]) }}
                    @if (! empty($c['is_lease_to_own']))
                        &middot; <span class="label label-default">{{ trans('admin/purchase-orders/general.disposition_retained') }}</span>
                    @endif
                </p>
                <div class="table-responsive rpt-table-scroll">
                    <table class="table table-striped table-condensed disp-table">
                        <thead>
                            <tr>
                                @if ($canEditAssets)
                                    <th class="disp-check-col"><input type="checkbox" class="disp-check-all" title="{{ trans('general.select_all') }}"></th>
                                @endif
                                <th>{{ trans('admin/purchase-orders/general.detail_serial') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.detail_asset_tag') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.disposition_assigned_to') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.disposition_action') }}</th>
                                <th class="disp-col-date">{{ trans('admin/purchase-orders/general.disposition_decommissioned_date') }}</th>
                                <th class="disp-col-cost">{{ trans('admin/purchase-orders/general.detail_buyout_cost') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.disposition_use') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.detail_ownership') }}</th>
                                <th>{{ trans('general.category') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.detail_model') }}</th>
                                <th>{{ trans('general.notes') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($c['assets'] as $a)
                                <tr data-asset-id="{{ $a['asset_id'] }}" data-contract="{{ $c['contract_id'] }}" data-serial="{{ $a['serial'] }}" data-tag="{{ $a['asset_tag'] }}" data-pane="disp-pane-{{ $i }}"
                                    data-status-id="{{ $a['status_id'] }}" data-decom="{{ $a['decommissioned_date'] }}" data-buyout="{{ $a['buyout_cost_raw'] > 0 ? $a['buyout_cost_raw'] : '' }}"
                                    @if ($a['archived']) class="text-muted disp-archived" @endif>
                                    @if ($canEditAssets)
                                        <td class="disp-check-col"><input type="checkbox" class="disp-row-check"></td>
                                    @endif
                                    <td><a href="{{ route('hardware.show', $a['asset_id']) }}" class="js-lightbox">{{ $a['serial'] }}</a></td>
                                    <td>{{ $a['asset_tag'] }}</td>
                                    {{-- Who holds it: person, room, or parent asset. The icon
                                         carries the flavour so a room does not read as a name. --}}
                                    <td class="disp-col-assigned">
                                        @if ($a['assigned_name'] !== '')
                                            <i class="fa-solid {{ $a['assigned_kind'] === 'user' ? 'fa-user' : ($a['assigned_kind'] === 'location' ? 'fa-location-dot' : 'fa-laptop') }} disp-assigned-icon" aria-hidden="true"></i>
                                            @if ($a['assigned_url'] !== '')
                                                <a href="{{ $a['assigned_url'] }}">{{ $a['assigned_name'] }}</a>
                                            @else
                                                {{ $a['assigned_name'] }}
                                            @endif
                                        @else
                                            <span class="text-muted">&mdash;</span>
                                        @endif
                                    </td>
                                    @php
                                        $statusClass = match ($a['status_type'] ?? null) {
                                            'deployable' => 'label-success',
                                            'pending' => 'label-warning',
                                            'undeployable' => 'label-danger',
                                            default => 'label-default',
                                        };
                                        $editAttrs = $canEditAssets ? 'disp-editable' : '';
                                    @endphp
                                    <td class="{{ $editAttrs }}" data-field="status_id">
                                        <span class="label {{ $statusClass }}">{{ $a['status'] }}</span>
                                        @if ($canEditAssets)
                                            <a href="#" class="disp-cell-edit" title="{{ trans('admin/purchase-orders/general.disposition_edit_value') }}"><i class="fa-solid fa-pencil" aria-hidden="true"></i></a>
                                        @endif
                                    </td>
                                    <td class="disp-col-date {{ $editAttrs }}" data-field="decommission_date">
                                        <span class="disp-cell-value">{{ $a['decommissioned_date'] }}</span>
                                        @if ($canEditAssets)
                                            <a href="#" class="disp-cell-edit" title="{{ trans('admin/purchase-orders/general.disposition_edit_value') }}"><i class="fa-solid fa-pencil" aria-hidden="true"></i></a>
                                        @endif
                                    </td>
                                    <td class="disp-col-cost {{ $editAttrs }}" data-field="buyout_cost">
                                        <span class="disp-cell-value">{{ $a['buyout_cost'] }}</span>
                                        @if ($canEditAssets)
                                            <a href="#" class="disp-cell-edit" title="{{ trans('admin/purchase-orders/general.disposition_edit_value') }}"><i class="fa-solid fa-pencil" aria-hidden="true"></i></a>
                                        @endif
                                    </td>
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

    @if ($canEditAssets)
        {{-- Bulk apply bar: acts on the checked rows of the active pane. A
             field left blank stays unchanged on the selected devices. --}}
        <div class="disp-bulk-bar">
            <select class="form-control input-sm disp-bulk-status">
                <option value="">{{ trans('admin/purchase-orders/general.disposition_bulk_keep_status') }}</option>
                @foreach ($statusOptions as $statusId => $statusName)
                    <option value="{{ $statusId }}">{{ $statusName }}</option>
                @endforeach
            </select>
            <input type="date" class="form-control input-sm disp-bulk-date"
                   title="{{ trans('admin/purchase-orders/general.disposition_decommissioned_date') }}">
            <input type="number" step="0.01" min="0" class="form-control input-sm disp-bulk-cost"
                   placeholder="{{ trans('admin/purchase-orders/general.detail_buyout_cost') }}">
            <button type="button" class="btn btn-sm btn-primary disp-bulk-apply" disabled>
                {{ trans('admin/purchase-orders/general.disposition_bulk_apply') }}
            </button>
            <span class="disp-bulk-count text-muted" aria-live="polite"></span>
            <span class="disp-bulk-hint text-muted">{{ trans('admin/purchase-orders/general.disposition_bulk_hint') }}</span>
        </div>
    @endif
@endif
</div>
