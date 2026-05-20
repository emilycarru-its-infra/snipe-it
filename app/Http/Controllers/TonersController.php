<?php

namespace App\Http\Controllers;

use App\Models\AssetModel;
use App\Models\Consumable;

/**
 * Per-printer (or per-asset-model) consumables stock dashboard. Mirrors the
 * spreadsheet ECU staff have been maintaining by hand — one card per asset
 * model, listing its compatible consumables with current stock and a
 * traffic-light indicator. The card grid is grouped by manufacturer so the
 * page reads at a glance.
 *
 * The page is driven entirely by the consumable -> asset-model pivot
 * introduced for printer-toner compatibility: a model only shows up here
 * when at least one consumable has been linked to it.
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
            ->orderBy('name')
            ->get();

        // Group cards by manufacturer for a spreadsheet-like layout — the
        // bench-side reference these printer pages are replacing organised
        // toners that way (HP, Canon, Ricoh blocks).
        $grouped = $models->groupBy(fn ($model) => $model->manufacturer?->name ?: trans('general.unknown'));

        return view('toners.index', [
            'modelGroups' => $grouped,
            'totalModels' => $models->count(),
            'totalConsumables' => $models->sum(fn ($m) => $m->compatibleConsumables->count()),
        ]);
    }
}
