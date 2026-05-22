{{--
    Per-printer (asset model) consumables stock dashboard. Reusable partial:
    used standalone at /toners and as a glance card above the consumables
    list. Renders a single flat grid of printer cards — masonry-style
    flow so short cards don't leave whitespace below them, and admins
    drag-drop to re-arrange.

    Drop position is persisted via POST /models/reorder which writes
    display_order on each model. Subsequent page loads sort by
    display_order ASC, name ASC.
--}}
@php
    if (! isset($printerModels)) {
        $printerModels = \App\Models\AssetModel::query()
            ->with([
                'manufacturer',
                'compatibleConsumables' => fn ($q) => $q->orderBy('name'),
            ])
            ->whereHas('compatibleConsumables')
            ->withCount('assets')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
        $totalModels      = $printerModels->count();
        $totalConsumables = $printerModels->sum(fn ($m) => $m->compatibleConsumables->count());
    }
    $canReorder = auth()->user()?->can('update', \App\Models\AssetModel::class) ?? false;
@endphp

@if ($totalModels > 0)
<div class="row">
    <div class="col-md-12 toner-dashboard-header">
        <h1 class="toner-dashboard-title">Toner</h1>
        @if ($canReorder)
            <span class="toner-dashboard-hint" data-tooltip="true"
                title="Drag any printer card by its grip to re-arrange. Order saves automatically.">
                <x-icon type="info" class="fa-fw" /> drag to reorder
            </span>
        @endif
    </div>
</div>

<div class="row toner-grid-row">
    <div class="toner-grid"
         data-reorder-url="{{ route('models.reorder') }}"
         data-can-reorder="{{ $canReorder ? '1' : '0' }}"
         data-csrf="{{ csrf_token() }}">
        @foreach ($printerModels as $model)
            @php
                $autoOrdering = $model->compatibleConsumables
                    ->contains(fn ($c) => (bool) ($c->on_maintenance_contract ?? false));
            @endphp
            <div class="toner-printer-card box box-default" data-model-id="{{ $model->id }}">
                <div class="box-header with-border toner-printer-card-header">
                    @if ($canReorder)
                        <span class="toner-printer-card-grip" title="Drag to reorder">
                            <i class="fa-solid fa-grip-vertical" aria-hidden="true"></i>
                        </span>
                    @endif
                    <div class="toner-printer-card-titleblock">
                        <h3 class="box-title toner-printer-card-title">{{ $model->name }}</h3>
                        @if ($model->manufacturer)
                            <div class="toner-printer-card-manufacturer">{{ $model->manufacturer->name }}</div>
                        @endif
                        <div class="toner-printer-card-meta">
                            <span class="toner-printer-count">
                                {{ $model->assets_count }} {{ \Illuminate\Support\Str::plural('printer', $model->assets_count) }}
                            </span>
                            @if ($autoOrdering)
                                <span class="label label-success toner-auto-order-badge"
                                      title="At least one compatible consumable is on a maintenance contract">
                                    <x-icon type="checkmark" class="fa-fw" /> Auto ordering enabled
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="box-body toner-printer-card-body">
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
                                    <a href="{{ route('consumables.show', $consumable->id) }}">{{ $consumable->name }}</a>
                                </td>
                                <td class="{{ $cellClass }}" style="width:60px; text-align:center; font-weight:bold;">{{ $remaining }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
</div>

@if ($canReorder)
{{-- Inline script so the partial works on both /toners (uses @stack) and
     /consumables (uses @section('moar_scripts') and ignores stacks).
     jQuery UI sortable is already bundled in vendor.js. --}}
<script nonce="{{ csrf_token() }}">
(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }
    ready(function () {
        var $ = window.jQuery;
        if (!$ || !$.fn || !$.fn.sortable) return;

        var $grid = $('.toner-grid');
        if (!$grid.length) return;

        var url  = $grid.data('reorder-url');
        var csrf = $grid.data('csrf');

        // Masonry-style packing: span each card across enough small grid
        // rows to cover its actual rendered height, so shorter cards
        // don't leave a void underneath the longer one in the same row.
        function reflow() {
            var styles  = window.getComputedStyle($grid[0]);
            var rowH    = parseInt(styles.gridAutoRows) || 10;
            var rowGap  = parseInt(styles.rowGap || styles.gridRowGap) || 0;
            $grid.children('.toner-printer-card').each(function () {
                this.style.gridRowEnd = ''; // reset so we measure natural height
                var h = this.getBoundingClientRect().height;
                var span = Math.max(1, Math.ceil((h + rowGap) / (rowH + rowGap)));
                this.style.gridRowEnd = 'span ' + span;
            });
        }
        reflow();
        window.addEventListener('resize', reflow);
        // Re-reflow after any images / fonts settle in the box bodies.
        setTimeout(reflow, 250);

        $grid.sortable({
            items: '> .toner-printer-card',
            handle: '.toner-printer-card-grip',
            placeholder: 'toner-printer-card-placeholder',
            forcePlaceholderSize: true,
            tolerance: 'pointer',
            opacity: 0.85,
            cursor: 'grabbing',
            start: function (evt, ui) {
                // Pull the placeholder out of the flex flow so it doesn't
                // inherit a huge row span and look weird mid-drag.
                ui.placeholder.css('grid-row-end', 'span ' + (parseInt(ui.item[0].style.gridRowEnd.replace('span', ''), 10) || 1));
            },
            update: function () {
                var ids = $grid.children('.toner-printer-card').map(function () {
                    return $(this).data('model-id');
                }).get();
                $.ajax({
                    url: url,
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    data: { ids: ids, _token: csrf }
                });
                reflow();
            }
        });
    });
})();
</script>
@endif
@endif

@push('css')
<style>
    .toner-dashboard-header {
        display: flex;
        align-items: baseline;
        gap: 16px;
        margin: 8px 0 22px;
    }
    .toner-dashboard-title {
        margin: 0;
        font-size: 28px;
        font-weight: 600;
        letter-spacing: 0.4px;
    }
    .toner-dashboard-hint {
        font-size: 12px;
        opacity: 0.6;
        font-style: italic;
    }

    .toner-grid-row { padding: 0 4px 28px; }

    .toner-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        grid-auto-rows: 8px;     /* tiny rows + per-card span = masonry flow */
        gap: 22px;
        width: 100%;
    }

    .toner-printer-card {
        margin: 0;
        align-self: start;       /* don't stretch cards to row height */
    }
    .toner-printer-card.ui-sortable-helper {
        transform: rotate(-1deg);
        box-shadow: 0 12px 32px rgba(0,0,0,0.25);
        opacity: 0.95;
    }
    .toner-printer-card-placeholder {
        border: 2px dashed rgba(127,127,127,0.45);
        background: rgba(127,127,127,0.06);
        border-radius: 4px;
    }

    .toner-printer-card-header {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 14px 16px 12px;
    }
    .toner-printer-card-grip {
        cursor: grab;
        opacity: 0.35;
        padding: 4px 4px 0;
        line-height: 1;
        user-select: none;
        font-size: 14px;
    }
    .toner-printer-card-grip:hover { opacity: 0.9; }
    .toner-printer-card-grip:active { cursor: grabbing; }
    .toner-printer-card-titleblock { flex: 1; min-width: 0; }
    .toner-printer-card-title {
        font-size: 18px;
        font-weight: 600;
        margin: 0 0 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .toner-printer-card-manufacturer {
        font-size: 12px;
        opacity: 0.6;
        margin: 0 0 6px;
    }
    .toner-printer-card-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        font-size: 13px;
    }
    .toner-printer-count {
        font-size: 13px;
        font-weight: 600;
        padding: 2px 9px;
        background: rgba(127,127,127,0.12);
        border-radius: 10px;
        white-space: nowrap;
    }
    .toner-auto-order-badge { font-size: 11px; font-weight: 600; padding: 3px 8px; }
    .toner-printer-card-body { padding: 0 0 6px; }
    .toner-printer-card-body table td { padding: 8px 12px; }
</style>
@endpush
