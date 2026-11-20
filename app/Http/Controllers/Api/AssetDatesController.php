<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Back-filling the dates the forecast reads, many assets at a time.
 *
 * A device with neither a purchase date nor an EOL date is invisible to
 * every planning year, and filling those in one PATCH at a time is slow and
 * gets the EOL wrong: changing a purchase date on an asset whose EOL was
 * derived from it leaves the old EOL behind and marks it explicit. Here a
 * purchase date re-derives a derived EOL from the model's EOL policy, an EOL
 * date given outright is recorded as explicit, and a null EOL hands the asset
 * back to the policy. Every asset is authorized and saved through the model,
 * so the change is logged on each asset's history exactly as an edit is.
 */
class AssetDatesController extends Controller
{
    public const MAX_ROWS = 500;

    /**
     * Apply the dates, all or nothing. `dry_run` reports what would change
     * without saving.
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorize('update', Asset::class);

        $validated = $request->validate([
            'dry_run' => 'nullable|boolean',
            'assets' => 'required|array|min:1|max:'.self::MAX_ROWS,
            'assets.*.id' => 'required|integer|distinct',
            'assets.*.purchase_date' => 'sometimes|nullable|date_format:Y-m-d',
            'assets.*.asset_eol_date' => 'sometimes|nullable|date_format:Y-m-d',
        ]);

        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $rows = collect($validated['assets']);

        $assets = Asset::with('model')->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');

        $missing = $rows->pluck('id')->reject(fn ($id) => $assets->has($id))->values();
        if ($missing->isNotEmpty()) {
            return response()->json(Helper::formatStandardApiResponse(
                'error',
                ['missing' => $missing],
                trans('admin/hardware/message.does_not_exist')
            ), 404);
        }

        foreach ($assets as $asset) {
            $this->authorize('update', $asset);
        }

        $results = [];
        $errors = [];

        DB::beginTransaction();

        foreach ($rows as $row) {
            $asset = $assets->get($row['id']);
            $before = $this->dates($asset);

            $this->apply($asset, $row);

            if (! $dryRun && $asset->isDirty() && ! $asset->save()) {
                $errors[$asset->id] = $asset->getErrors();

                continue;
            }

            $after = $this->dates($asset);
            $results[] = array_merge(['id' => (int) $asset->id, 'asset_tag' => $asset->asset_tag], $after, [
                'changed' => array_keys(array_diff_assoc(
                    array_map('strval', $after),
                    array_map('strval', $before)
                )),
            ]);
        }

        if ($errors || $dryRun) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        if ($errors) {
            return response()->json(Helper::formatStandardApiResponse('error', ['errors' => $errors], trans('admin/hardware/message.update.error')), 422);
        }

        return response()->json(Helper::formatStandardApiResponse('success', [
            'dry_run' => $dryRun,
            'total' => count($results),
            'changed' => collect($results)->filter(fn ($r) => $r['changed'] !== [])->count(),
            'rows' => $results,
        ], $dryRun ? null : trans('admin/hardware/message.update.success')));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function apply(Asset $asset, array $row): void
    {
        if (array_key_exists('purchase_date', $row)) {
            $asset->purchase_date = $row['purchase_date'];
        }

        if (array_key_exists('asset_eol_date', $row) && $row['asset_eol_date'] !== null) {
            $asset->asset_eol_date = $row['asset_eol_date'];
            $asset->eol_explicit = true;

            return;
        }

        // A null EOL returns the asset to its model's policy; otherwise a
        // derived EOL follows its purchase date. An explicit EOL stays put.
        if (array_key_exists('asset_eol_date', $row)) {
            $asset->eol_explicit = false;
        }

        if (! $asset->eol_explicit) {
            $months = (int) $asset->model?->eol;
            $asset->asset_eol_date = ($asset->purchase_date && $months > 0)
                ? Carbon::parse($asset->purchase_date)->addMonths($months)->format('Y-m-d')
                : null;
        }
    }

    /**
     * @return array{purchase_date: string|null, asset_eol_date: string|null, eol_explicit: bool}
     */
    private function dates(Asset $asset): array
    {
        return [
            'purchase_date' => $asset->purchase_date ? Carbon::parse($asset->purchase_date)->format('Y-m-d') : null,
            'asset_eol_date' => $asset->asset_eol_date ? Carbon::parse($asset->asset_eol_date)->format('Y-m-d') : null,
            'eol_explicit' => (bool) $asset->eol_explicit,
        ];
    }
}
