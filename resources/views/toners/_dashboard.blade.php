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
        $modelGroups      = $_printerModels->groupBy(fn ($m) => $m->manufacturer?->name ?: trans('general.unknown'));
        $totalModels      = $_printerModels->count();
        $totalConsumables = $_printerModels->sum(fn ($m) => $m->compatibleConsumables->count());
    }
@endphp

@if ($totalModels > 0)
<div class="row">
    <div class="col-md-12">
        <p class="text-muted">
            {{ trans('admin/toners/general.intro') }}
            <strong>{{ $totalModels }}</strong>
            {{ trans_choice('admin/toners/general.model_count', $totalModels) }},
            <strong>{{ $totalConsumables }}</strong>
            {{ trans_choice('admin/toners/general.consumable_count', $totalConsumables) }}.
        </p>
    </div>
</div>

@foreach ($modelGroups as $manufacturerName => $models)
    <div class="row">
        <div class="col-md-12">
            <h2 style="margin-top:24px; padding-bottom:8px; border-bottom:1px solid #555;">
                {{ $manufacturerName }}
            </h2>
        </div>
    </div>
    <div class="row">
        @foreach ($models as $model)
            <div class="col-md-4 col-sm-6">
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            {{ $model->name }}
                            <small class="text-muted">(×{{ $model->assets_count }})</small>
                        </h3>
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
