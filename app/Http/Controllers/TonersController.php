<?php

namespace App\Http\Controllers;

use App\Models\AssetModel;
use App\Models\Consumable;

/**
 * Per-printer (asset-model) consumables stock dashboard. Mirrors the
 * spreadsheet ECU staff have been maintaining by hand: one card per
 * printer model, listing its compatible toner / ink consumables with
 * current stock and a traffic-light indicator. Cards are arranged in a
 * single flat grid, drag-drop reorderable by admins via display_order.
 *
 * Driven entirely by the consumable → asset-model pivot — a printer
 * only surfaces here once at least one consumable is linked to it.
 */
class TonersController extends Controller
{
    public function index()
    {
        $this->authorize('view', Consumable::class);

        $models = AssetModel::query()
            ->with([
                'manufacturer',
                'category',
                'compatibleConsumables' => fn ($query) => $query->orderBy('name'),
            ])
            ->whereHas('compatibleConsumables')
            ->withCount('assets')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return view('toners.index', [
            'printerModels'    => $models,
            'totalModels'      => $models->count(),
            'totalConsumables' => $models->sum(fn ($m) => $m->compatibleConsumables->count()),
        ]);
    }
}
