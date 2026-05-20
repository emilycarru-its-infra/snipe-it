{{--
    Per-printer (asset model) consumables stock dashboard. Reusable partial:
    used standalone at /toners and as a glance card above the consumables list.

    If the host view doesn't pre-fetch ($modelGroups), the partial loads the
    data itself so it can be dropped in anywhere without controller wiring.
--}}
@php
    if (! isset($modelGroups)) {
        $_printerModels = \App\Models\AssetModel::query()
            ->with([
                'manufacturer',
                'compatibleConsumables' => fn ($q) => $q->orderBy('name'),
            ])
            ->whereHas('compatibleConsumables')
            ->withCount('assets')
            ->orderBy('name')
            ->get();
        // Order subsections by manufacturer.display_order ASC, name ASC fallback
        // for any manufacturer that hasn't been re-ordered yet.
        $modelGroups = $_printerModels
            ->sortBy(fn ($m) => sprintf('%05d|%s',
                $m->manufacturer?->display_order ?? 999,
                strtolower($m->manufacturer?->name ?? '~')))
            ->groupBy(fn ($m) => $m->manufacturer?->name ?: trans('general.unknown'));
        $totalModels      = $_printerModels->count();
        $totalConsumables = $_printerModels->sum(fn ($m) => $m->compatibleConsumables->count());
    }
@endphp

@if ($totalModels > 0)
<div class="row">
    <div class="col-md-12">
        <h1 class="toner-dashboard-title">Toner</h1>
    </div>
</div>

@foreach ($modelGroups as $manufacturerName => $models)
    @php
        $_manufacturerId = $models->first()?->manufacturer?->id;
    @endphp
    <div class="row">
        <div class="col-md-12">
            <h2 class="toner-dashboard-manufacturer">
                <span class="toner-dashboard-manufacturer-name">{{ $manufacturerName }}</span>
                @can('update', \App\Models\Manufacturer::class)
                    @if ($_manufacturerId)
                        <span class="toner-dashboard-manufacturer-controls">
                            <form action="{{ route('manufacturers.move-up', $_manufacturerId) }}" method="POST" class="toner-reorder-form">
                                @csrf
                                <button type="submit" class="btn btn-xs btn-default" data-tooltip="true" title="Move up">
                                    <x-icon type="arrow-up" />
                                    <span class="sr-only">Move up</span>
                                </button>
                            </form>
                            <form action="{{ route('manufacturers.move-down', $_manufacturerId) }}" method="POST" class="toner-reorder-form">
                                @csrf
                                <button type="submit" class="btn btn-xs btn-default" data-tooltip="true" title="Move down">
                                    <x-icon type="arrow-down" />
                                    <span class="sr-only">Move down</span>
                                </button>
                            </form>
                        </span>
                    @endif
                @endcan
            </h2>
        </div>
    </div>
    <div class="row">
        @foreach ($models as $model)
            @php
                // "Auto ordering enabled" is a printer-level claim derived
                // from its consumables: a printer is on a managed-print
                // contract when at least one of its compatible toners is
                // marked on_maintenance_contract (per ECU's consumable flag).
                $autoOrdering = $model->compatibleConsumables
                    ->contains(fn ($c) => (bool) ($c->on_maintenance_contract ?? false));
            @endphp
            <div class="col-md-4 col-sm-6">
                <div class="box box-default toner-printer-card">
                    <div class="box-header with-border">
                        <h3 class="box-title toner-printer-card-title">
                            {{ $model->name }}
                        </h3>
                        <div class="toner-printer-card-meta">
                            <span class="toner-printer-count">
                                {{ $model->assets_count }} {{ \Illuminate\Support\Str::plural('printer', $model->assets_count) }}
                            </span>
                            @if ($autoOrdering)
                                <span class="label label-success toner-auto-order-badge" title="At least one compatible consumable is marked on_maintenance_contract">
                                    <x-icon type="checkmark" class="fa-fw" /> Auto ordering enabled
                                </span>
                            @endif
                        </div>
                    </div>
                    <div class="box-body" style="padding:0;">
                        <table class="table table-striped" style="margin-bottom:0;">
                            <tbody>
                            @foreach ($model->compatibleConsumables as $consumable)
                                @php
                                    $remaining = (int) $consumable->numRemaining();
                                    $min = (int) ($consumable->min_amt ?? 0);
                                    if ($remaining <= 0) {
                                        $cellClass = 'bg-red';
                                    } elseif ($min > 0 && $remaining <= $min) {
                                        $cellClass = 'bg-yellow';
                                    } else {
                                        $cellClass = 'bg-green';
                                    }
                                @endphp
                                <tr>
                                    <td>
                                        <a href="{{ route('consumables.show', $consumable->id) }}">
                                            {{ $consumable->name }}
                                        </a>
                                    </td>
                                    <td class="{{ $cellClass }}" style="width:60px; text-align:center; font-weight:bold;">
                                        {{ $remaining }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endforeach
@endif

@push('css')
<style>
    .toner-dashboard-title {
        margin: 4px 0 18px;
        font-size: 28px;
        font-weight: 600;
        letter-spacing: 0.4px;
    }
    .toner-dashboard-manufacturer {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-top: 24px;
        padding-bottom: 8px;
        border-bottom: 1px solid rgba(127,127,127,0.35);
        font-size: 22px;
        font-weight: 500;
    }
    .toner-dashboard-manufacturer-name { flex: 1; }
    .toner-dashboard-manufacturer-controls {
        display: inline-flex;
        gap: 4px;
        font-size: 12px;
        opacity: 0.7;
    }
    .toner-dashboard-manufacturer-controls:hover { opacity: 1; }
    .toner-reorder-form { display: inline-block; margin: 0; }
    .toner-printer-card-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 4px;
    }
    .toner-printer-card-meta {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        font-size: 13px;
        margin-top: 4px;
    }
    .toner-printer-count {
        font-size: 14px;
        font-weight: 600;
        padding: 3px 10px;
        background: rgba(127,127,127,0.12);
        border-radius: 10px;
        white-space: nowrap;
    }
    .toner-auto-order-badge {
        font-size: 11px;
        font-weight: 600;
        padding: 4px 8px;
    }
</style>
@endpush
