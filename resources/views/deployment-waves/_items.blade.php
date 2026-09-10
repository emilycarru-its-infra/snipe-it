{{-- The wave's one table.

     It used to be two — a "who was invited" roster and a device board —
     with the same twenty rows in each, because a wave of individually
     assigned laptops is people and devices in the same breath. Merged: one
     row per swap, carrying both halves.

     Waves come in two shapes and the table follows. A wave of ASSIGNED
     devices is a list of people — each row is a person, their current
     machine, whether it is actually due, what they said on the form, and
     where their order stands; the person self-provisions and the wave
     tracks them. A wave of SHARED devices is fleet we provision ourselves —
     nobody to invite, nothing to answer — so it groups by model and lists
     the units under each, where they live and where each one stands. --}}
@php
    $membership = new \App\Services\Deployments\WaveMembership;

    $holderOf = fn ($item) => $item->replacesAsset?->holderUser()
        ?? $item->asset?->holderUser()
        ?? $item->assignedUser;

    $userHeld = $wave->items->filter(fn ($item) => $holderOf($item) !== null)->count();
    $isAssigned = $wave->items->isEmpty() || $userHeld * 2 >= $wave->items->count();

    // A wave whose type moves devices — relocation, exhibit — is built
    // from equipment we already own. Nothing is replaced, nothing is
    // ordered and nothing arrives, so every column that reports on
    // procuring an incoming device is noise here and is dropped rather
    // than rendered as a column of dashes.
    $existingEquipment = (bool) $wave->type?->moves_devices;

    // A wave that replaces nothing — a new teaching lab, a first machine
    // for a new hire — has no outgoing device to report on. Its "Replaces"
    // and "Projected Replacement" columns are a wall of dashes and a
    // forecast of a swap that is not happening, so they come out.
    $replacing = $wave->replacesDevices();

    $ineligibleIds = $announceIneligible->pluck('user.id')->all();
    $ineligibleReasons = $announceIneligible->keyBy('user.id');
    $intentByUser = $intentRows->keyBy(fn ($row) => $row['user']?->id);
    $ordered = $announceRecipients->filter(fn ($row) => isset($waveOrders[$row['user']->id]))->count();
@endphp

<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">
            {{ trans('admin/deployments/general.board_title') }} — {{ $wave->items->count() }} {{ trans('admin/deployments/general.device') }}(s)
            <span class="label label-default">{{ $isAssigned ? trans('admin/deployments/general.usage_assigned') : trans('admin/deployments/general.usage_shared') }}</span>
        </h3>
        <div class="box-tools pull-right">
            @if ($isAssigned && $announceRecipients->isNotEmpty())
                <span class="label label-{{ $ordered === $announceRecipients->count() ? 'success' : 'default' }}">
                    {{ trans('admin/deployments/general.roster_ordered_count', ['ordered' => $ordered, 'total' => $announceRecipients->count()]) }}
                </span>
            @endif
            @if ($replacing && ($projectedTotal ?? 0) > 0)
                <span class="label label-info" title="{{ trans('admin/deployments/general.projected_cost_help') }}">
                    {{ trans('admin/deployments/general.projected_cost_total', ['total' => number_format($projectedTotal, 2)]) }}
                </span>
            @endif
            <form method="POST" action="{{ route('deployment-waves.destroy', $wave) }}" style="display:inline-block;" onsubmit="return confirm('{{ trans('admin/deployments/general.delete_confirm') }}');">
                {{ csrf_field() }}@method('DELETE')
                <button type="submit" class="btn btn-xs btn-danger" title="{{ trans('general.delete') }}"><i class="fas fa-trash"></i> {{ trans('general.delete') }}</button>
            </form>
        </div>
    </div>

    @if ($isAssigned && $announceIneligible->isNotEmpty())
        <div class="box-body" style="padding-bottom:0;">
            <p class="text-warning">
                <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                {{ trans_choice('admin/deployments/general.roster_ineligible_warning', $announceIneligible->count(), ['count' => $announceIneligible->count()]) }}
            </p>
        </div>
    @endif

    <div class="box-body table-responsive no-padding">
    @if ($isAssigned)
        <table class="table table-hover table-striped wave-items-table">
            <thead>
                <tr>
                    <th>{{ trans('admin/deployments/general.stage') }}</th>
                    <th>{{ trans('admin/deployments/general.roster_person') }}</th>
                    <th>{{ trans('admin/deployments/general.roster_device') }}</th>
                    @unless ($existingEquipment)
                        <th>{{ trans('admin/deployments/general.roster_due') }}</th>
                        <th>{{ trans('admin/deployments/general.roster_said') }}</th>
                        <th>{{ trans('admin/deployments/general.roster_actual') }}</th>
                        <th>{{ trans('admin/deployments/general.roster_ordered') }}</th>
                        <th class="col-repl">{{ trans('admin/deployments/general.replacement') }}</th>
                        <th>{{ trans('admin/deployments/general.arrival_status') }}</th>
                    @endunless
                    <th>{{ trans('admin/deployments/general.target_deploy_date') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($wave->items as $item)
                @php
                    $holder = $holderOf($item);
                    $holderLocation = $item->replacesAsset?->holderLocation() ?? $item->asset?->holderLocation();
                    $current = $item->replacesAsset;
                    // The unit this row is about. On a refresh that is the
                    // outgoing device; on a wave of devices that already
                    // exist (relocation, exhibit) there is no outgoing one
                    // and the row's own asset is the device.
                    $device = $current ?? $item->asset;
                    $due = $current ? $membership->dueDate($current) : null;
                    $intent = $holder ? ($intentByUser[$holder->id] ?? null) : null;
                    $order = $holder ? ($waveOrders[$holder->id] ?? null) : null;
                    $flagged = $holder && in_array($holder->id, $ineligibleIds, true);
                @endphp
                <tr @class(['warning' => $flagged])>
                    <td>
                        <form method="POST" action="{{ route('deployment-items.stage', $item) }}" class="form-inline" style="margin:0;">
                            {{ csrf_field() }}
                            <select name="stage_id" class="form-control input-sm" onchange="this.form.submit()" style="border-left:5px solid {{ $item->stageColor() }};">
                                @foreach ($stages as $st)
                                    <option value="{{ $st->id }}" {{ (int) $item->stage_id === (int) $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
                                @endforeach
                            </select>
                        </form>
                    </td>
                    <td>
                        @if ($holder)
                            <a class="js-lightbox" href="{{ route('users.show', $holder) }}">{{ $holder->present()->fullName }}</a>
                            @if ($flagged)
                                <i class="fas fa-triangle-exclamation text-warning" aria-hidden="true"
                                   title="{{ trans('admin/deployments/general.roster_reason_'.$ineligibleReasons[$holder->id]['reason']) }}"></i>
                            @endif
                        @elseif ($holderLocation)
                            <i class="fas fa-location-dot text-muted" aria-hidden="true"></i>
                            <a href="{{ route('locations.show', $holderLocation) }}">{{ $holderLocation->name }}</a>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td>
                        @if ($device)
                            <a class="js-lightbox" href="{{ route('hardware.show', $device) }}">{{ $device->asset_tag ?: $device->name }}</a>
                            @if ($device->name && $device->asset_tag)<span class="text-muted">· {{ $device->name }}</span>@endif
                        @else — @endif
                    </td>
                    @unless ($existingEquipment)
                        <td>
                            {{ $due?->toDateString() ?? '—' }}
                            @if ($due && $current->asset_eol_date && $due->isSameDay(\Carbon\Carbon::parse($current->asset_eol_date)))
                                <span class="text-muted">{{ trans('admin/deployments/general.roster_due_eol') }}</span>
                            @elseif ($due)
                                <span class="text-muted">{{ trans('admin/deployments/general.roster_due_lease') }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($intent)
                                {{ trans('admin/user-agreements/general.intent_'.$intent['intent']) }}
                            @else
                                <span class="text-muted">{{ trans('admin/deployments/general.roster_no_answer') }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($intent)
                                <span class="{{ $intent['matches'] ? '' : 'text-danger' }}">
                                    {{ trans('admin/user-agreements/general.intent_actual_'.$intent['actual']) }}
                                </span>
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </td>
                        <td>
                            @if ($order)
                                <a href="{{ route('procurement.approvals', ['status' => $order->status]) }}">{{ $order->reference() }}</a>
                                <span class="label label-default">{{ $order->displayStatus() }}</span>
                            @else
                                <span class="text-muted">{{ trans('admin/deployments/general.roster_not_ordered') }}</span>
                            @endif
                        </td>
                        <td class="col-repl">
                            {{-- Block-form @php only: Blade pairs every @php with
                                 the next @endphp file-wide, so one inline form
                                 here silently swallows the next block. --}}
                            @php
                                $catalog = $item->model?->refreshCatalogItem;
                            @endphp
                            {{ $item->plannedDeviceLabel() ?: '—' }}
                            @if ($catalog)
                                <span class="text-muted">${{ number_format($catalog->effectiveCost(), 2) }}</span>
                                @if ($catalog->isEstimate())<span class="label label-default">{{ trans('admin/purchase-orders/general.price_estimate') }}</span>@endif
                            @elseif ($current?->purchase_cost)
                                <span class="text-muted" title="{{ trans('admin/purchase-orders/general.forecast_basis_original') }}">${{ number_format((float) $current->purchase_cost, 2) }}</span>
                            @endif
                        </td>
                        <td>@include('deployment-waves._arrival-badge', ['badge' => $timeline->itemBadge($item)])</td>
                    @endunless
                    <td>{{ optional($item->target_deploy_date)->toDateString() ?: '—' }}</td>
                    <td class="text-right">
                        <form method="POST" action="{{ route('deployment-items.destroy', $item) }}" style="display:inline-block;" onsubmit="return confirm('{{ trans('admin/deployments/general.item_delete_confirm') }}');">
                            {{ csrf_field() }}@method('DELETE')
                            <button type="submit" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $existingEquipment ? 5 : 11 }}" class="text-center text-muted">{{ trans('admin/deployments/general.no_items') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    @else
        {{-- Shared fleet: grouped by model, units nested under a chevron,
             open by default — the group line is the summary, the units are
             the work. --}}
        <table class="table table-hover wave-items-table">
            <thead>
                <tr>
                    <th style="width:34px;"></th>
                    <th>{{ trans('admin/deployments/general.stage') }}</th>
                    <th>{{ trans('admin/deployments/general.device') }}</th>
                    <th>{{ trans('admin/deployments/general.checked_out_to') }}</th>
                    @if ($replacing)
                        <th>{{ trans('admin/deployments/general.replaces') }}</th>
                        <th>{{ trans('admin/deployments/general.projected_replacement') }}</th>
                    @endif
                    @unless ($existingEquipment)
                        <th>{{ trans('admin/deployments/general.arrival_status') }}</th>
                    @endunless
                    <th>{{ trans('admin/deployments/general.target_deploy_date') }}</th>
                    <th></th>
                </tr>
            </thead>
            @foreach ($wave->items->groupBy(fn ($item) => $item->fleetGroupLabel() ?: '—') as $modelName => $group)
                <tbody class="wave-model-group">
                    <tr class="wave-group-head active">
                        <td>
                            <button type="button" class="btn btn-xs btn-default wave-group-toggle" aria-expanded="true">
                                <i class="fas fa-chevron-down" aria-hidden="true"></i>
                            </button>
                        </td>
                        <td colspan="{{ $replacing ? 4 : 3 }}">
                            <strong>{{ $modelName }}</strong>
                            <span class="text-muted">· {{ $group->count() }} {{ trans('admin/deployments/general.device') }}(s)</span>
                        </td>
                        @if ($replacing)
                            @php
                                $groupProjected = $group->sum(fn ($item) => $item->model?->refreshCatalogItem?->effectiveCost()
                                    ?? (float) ($item->replacesAsset->purchase_cost ?? 0));
                            @endphp
                            <td><strong>${{ number_format($groupProjected, 2) }}</strong></td>
                        @endif
                        <td colspan="{{ $existingEquipment ? 2 : 3 }}" class="text-muted">
                            {{ $group->groupBy(fn ($item) => $item->stage?->name ?: '—')->map(fn ($sub, $name) => $sub->count().' '.$name)->implode(' · ') }}
                        </td>
                    </tr>
                    @foreach ($group as $item)
                    <tr class="wave-group-unit">
                        <td></td>
                        <td>
                            <form method="POST" action="{{ route('deployment-items.stage', $item) }}" class="form-inline" style="margin:0;">
                                {{ csrf_field() }}
                                <select name="stage_id" class="form-control input-sm" onchange="this.form.submit()" style="border-left:5px solid {{ $item->stageColor() }};">
                                    @foreach ($stages as $st)
                                        <option value="{{ $st->id }}" {{ (int) $item->stage_id === (int) $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
                                    @endforeach
                                </select>
                            </form>
                        </td>
                        <td>
                            @php
                                $unit = $item->asset ?? $item->replacesAsset;
                            @endphp
                            @if ($unit)
                                <a class="js-lightbox" href="{{ route('hardware.show', $unit) }}">{{ $unit->asset_tag ?: $unit->name }}</a>
                                @if ($unit->name && $unit->asset_tag)<span class="text-muted">· {{ $unit->name }}</span>@endif
                            @else
                                <span class="text-muted">{{ $item->deviceLabel() }}</span>
                            @endif
                        </td>
                        <td>
                            @php
                                $holder = $holderOf($item);
                                $holderLocation = $item->replacesAsset?->holderLocation() ?? $item->asset?->holderLocation();
                            @endphp
                            @if ($holder)
                                <a class="js-lightbox" href="{{ route('users.show', $holder) }}">{{ $holder->present()->fullName }}</a>
                            @elseif ($holderLocation)
                                <i class="fas fa-location-dot text-muted" aria-hidden="true"></i>
                                <a href="{{ route('locations.show', $holderLocation) }}">{{ $holderLocation->name }}</a>
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </td>
                        @if ($replacing)
                            <td>
                                @if ($item->replacesAsset)
                                    <a class="js-lightbox" href="{{ route('hardware.show', $item->replacesAsset) }}">{{ $item->replacesAsset->asset_tag ?: $item->replacesAsset->name }}</a>
                                    @if ($item->replacesAsset->serial)<span class="text-muted">· {{ $item->replacesAsset->serial }}</span>@endif
                                @else — @endif
                            </td>
                            <td>
                                @php
                                    $catalog = $item->model?->refreshCatalogItem;
                                @endphp
                                @if ($catalog)
                                    ${{ number_format($catalog->effectiveCost(), 2) }}
                                    @if ($catalog->isEstimate())<span class="label label-default">{{ trans('admin/purchase-orders/general.price_estimate') }}</span>@endif
                                @elseif ($item->replacesAsset?->purchase_cost)
                                    <span class="text-muted">${{ number_format((float) $item->replacesAsset->purchase_cost, 2) }}</span>
                                @else — @endif
                            </td>
                        @endif
                        @unless ($existingEquipment)
                            <td>@include('deployment-waves._arrival-badge', ['badge' => $timeline->itemBadge($item)])</td>
                        @endunless
                        <td>{{ optional($item->target_deploy_date)->toDateString() ?: '—' }}</td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('deployment-items.destroy', $item) }}" style="display:inline-block;" onsubmit="return confirm('{{ trans('admin/deployments/general.item_delete_confirm') }}');">
                                {{ csrf_field() }}@method('DELETE')
                                <button type="submit" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            @endforeach
            @if ($wave->items->isEmpty())
                <tbody><tr><td colspan="{{ 6 + ($replacing ? 2 : 0) + ($existingEquipment ? 0 : 1) }}" class="text-center text-muted">{{ trans('admin/deployments/general.no_items') }}</td></tr></tbody>
            @endif
        </table>

        <style>
            .wave-group-head td { background: color-mix(in srgb, var(--color-fg, #333) 5%, var(--box-bg, #fff)); border-top: 2px solid var(--box-header-bottom-border-color, #e4e9ee); }
            .wave-group-toggle { opacity: .7; }
            .wave-group-toggle:hover { opacity: 1; }
        </style>
        <script nonce="{{ csrf_token() }}">
            document.querySelectorAll('.wave-group-toggle').forEach(function (toggle) {
                toggle.addEventListener('click', function () {
                    var body = toggle.closest('tbody');
                    var expanded = toggle.getAttribute('aria-expanded') === 'true';
                    toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    toggle.querySelector('i').className = expanded ? 'fas fa-chevron-right' : 'fas fa-chevron-down';
                    body.querySelectorAll('.wave-group-unit').forEach(function (row) {
                        row.style.display = expanded ? 'none' : '';
                    });
                });
            });
        </script>
    @endif
    </div>

</div>
