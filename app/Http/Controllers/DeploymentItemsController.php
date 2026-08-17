<?php

namespace App\Http\Controllers;

use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentWave;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Per-device rows on a deployment wave board. `store` adds a blank/manual
 * item; `updateStage` advances a device through the pipeline (and, when the
 * target stage maps to a Snipe status, flips the linked asset's status);
 * `update` edits the row fields; `destroy` removes it. Gated by the
 * deployments module permission (read+write).
 */
class DeploymentItemsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $wave = DeploymentWave::findOrFail((int) $request->input('wave_id'));

        $attributes = $request->only([
            'asset_id', 'replaces_asset_id', 'model_id', 'stage_id',
            'assigned_user_id', 'assigned_tech_id', 'storage_location_id',
            'target_deploy_date', 'notes',
        ]);
        $attributes['wave_id'] = $wave->id;
        $attributes['stage_id'] = $attributes['stage_id']
            ?: DeploymentStage::where('slug', 'planned')->value('id');

        // Adding a device the wave already has is a correction to that row,
        // not a second device.
        if ($existing = DeploymentItem::onWave($wave->id, (int) $request->input('asset_id') ?: null)) {
            if (! $existing->absorb($attributes)) {
                return redirect()->back()->withInput()->withErrors($existing->getErrors());
            }

            return redirect()->route('deployment-waves.show', $wave)
                ->with('success', trans('admin/deployments/general.item_merged'));
        }

        $item = new DeploymentItem($attributes);

        if (! $item->save()) {
            return redirect()->back()->withInput()->withErrors($item->getErrors());
        }

        return redirect()->route('deployment-waves.show', $wave)
            ->with('success', trans('admin/deployments/general.item_added'));
    }

    /**
     * Add existing devices to a wave — the relocation/exhibit shape, where
     * the wave allocates equipment the university already owns rather than
     * planning replacements. Each asset becomes an item whose `asset_id`
     * IS the deployed device (no order line, no replaced device), so the
     * stage pipeline runs on it directly and a terminal stage can move it
     * to the wave's target location when the wave's type says so.
     */
    public function storeExisting(Request $request, DeploymentWave $deploymentWave): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'integer',
        ]);

        $plannedStageId = DeploymentStage::where('slug', 'planned')->value('id');

        // Skip assets already tracked by any deployment item, either side.
        $ids = array_map('intval', $request->input('asset_ids'));
        $tracked = DeploymentItem::query()
            ->whereIn('asset_id', $ids)
            ->pluck('asset_id')
            ->merge(DeploymentItem::query()->whereIn('replaces_asset_id', $ids)->pluck('replaces_asset_id'))
            ->unique()
            ->all();

        $added = 0;
        foreach ($ids as $assetId) {
            if (in_array($assetId, $tracked, true)) {
                continue;
            }

            $asset = \App\Models\Asset::find($assetId);
            if (! $asset) {
                continue;
            }

            $item = new DeploymentItem([
                'wave_id' => $deploymentWave->id,
                'asset_id' => $asset->id,
                'model_id' => $asset->model_id,
                'stage_id' => $plannedStageId,
            ]);
            if ($item->save()) {
                $added++;
            }
        }

        return redirect()->route('deployment-waves.show', $deploymentWave)
            ->with('success', trans('admin/deployments/general.existing_added', ['count' => $added]));
    }

    /**
     * Advance a device to a stage. If the stage maps to a Snipe status and
     * the item has an asset, flip the asset's status too. Terminal stages
     * stamp deployed_at.
     */
    public function updateStage(Request $request, DeploymentItem $deploymentItem): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $stage = DeploymentStage::findOrFail((int) $request->input('stage_id'));

        $deploymentItem->stage_id = $stage->id;
        $deploymentItem->deployed_at = $stage->is_terminal ? now() : $deploymentItem->deployed_at;

        if (! $deploymentItem->save()) {
            return redirect()->back()->withErrors($deploymentItem->getErrors());
        }

        // Bridges: status flip, and the terminal move on relocating waves.
        $deploymentItem->applyStageEffects($stage);

        return redirect()->route('deployment-waves.show', $deploymentItem->wave_id)
            ->with('success', trans('admin/deployments/general.stage_updated'));
    }

    /**
     * Bulk stage move from the board's device table. One gate is enforced
     * here and nowhere softer: a device leaves Planned only when it is on a
     * real order line (requisition → purchase order → ordered in
     * procurement, or a manual order link) — planning is free, "ordered"
     * is a fact from the money side, not an opinion. Later stages move
     * freely both directions; terminal stages stamp deployed_at; a stage
     * with maps_to_status_id flips the linked asset's status, same as the
     * single-item move.
     */
    public function bulkStage(Request $request): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'integer',
            'stage_id' => 'required|integer|exists:deployment_stages,id',
        ]);

        $stage = DeploymentStage::findOrFail((int) $request->input('stage_id'));

        $items = DeploymentItem::query()
            ->whereIn('id', $request->input('item_ids'))
            ->get();

        $moved = 0;
        $gated = 0;
        foreach ($items as $item) {
            $fromPlanned = ! $item->stage || $item->stage->slug === 'planned';
            $leavingPlanned = $fromPlanned && $stage->slug !== 'planned';

            // The gate is about money: a planned replacement can't be
            // "ordered" without an order line. A device that already
            // exists (stock-covered, relocation, exhibit) has nothing to
            // order — it moves freely.
            if ($leavingPlanned && ! $item->order_item_id && ! $item->asset_id) {
                $gated++;

                continue;
            }

            $item->stage_id = $stage->id;
            $item->deployed_at = $stage->is_terminal ? now() : $item->deployed_at;
            if (! $item->save()) {
                continue;
            }

            $item->applyStageEffects($stage);

            $moved++;
        }

        $redirect = redirect()->back();
        if ($moved > 0) {
            $redirect->with('success', trans('admin/deployments/general.bulk_moved', ['count' => $moved, 'stage' => $stage->name]));
        }
        if ($gated > 0) {
            $redirect->with('warning', trans('admin/deployments/general.bulk_gated', ['count' => $gated]));
        }

        return $redirect;
    }

    /**
     * Bulk-assign devices to a named group — the cohort that moves through
     * the stages together (a classroom, a department's refresh). An empty
     * label clears the group.
     */
    public function bulkGroup(Request $request): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'integer',
            'group_label' => 'nullable|string|max:191',
        ]);

        $label = trim((string) $request->input('group_label')) ?: null;

        $count = DeploymentItem::query()
            ->whereIn('id', $request->input('item_ids'))
            ->update(['group_label' => $label]);

        return redirect()->back()->with('success', $label
            ? trans('admin/deployments/general.group_set', ['count' => $count, 'group' => $label])
            : trans('admin/deployments/general.group_cleared', ['count' => $count]));
    }

    public function update(Request $request, DeploymentItem $deploymentItem): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $deploymentItem->fill($request->only([
            'asset_id', 'replaces_asset_id', 'model_id', 'stage_id',
            'assigned_user_id', 'assigned_tech_id', 'storage_location_id',
            'target_deploy_date', 'notes',
        ]));

        // Pointing this row at a device the wave already tracks would make
        // the twin by hand; the row that has the device keeps it.
        if ($deploymentItem->isDirty('asset_id') && $deploymentItem->conflictsOnWave($deploymentItem->asset_id)) {
            return redirect()->back()->withInput()
                ->with('error', trans('admin/deployments/general.item_duplicate_asset'));
        }

        if (! $deploymentItem->save()) {
            return redirect()->back()->withInput()->withErrors($deploymentItem->getErrors());
        }

        return redirect()->route('deployment-waves.show', $deploymentItem->wave_id)
            ->with('success', trans('admin/deployments/general.item_updated'));
    }

    public function destroy(DeploymentItem $deploymentItem): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $waveId = $deploymentItem->wave_id;
        $deploymentItem->delete();

        return redirect()->route('deployment-waves.show', $waveId)
            ->with('success', trans('admin/deployments/general.item_deleted'));
    }
}
