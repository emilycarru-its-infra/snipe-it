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

        $item = new DeploymentItem;
        $item->fill($request->only([
            'wave_id', 'asset_id', 'replaces_asset_id', 'model_id', 'stage_id',
            'assigned_user_id', 'assigned_tech_id', 'storage_location_id',
            'target_deploy_date', 'notes',
        ]));
        if (! $item->stage_id) {
            $item->stage_id = DeploymentStage::where('slug', 'planned')->value('id');
        }

        if (! $item->save()) {
            return redirect()->back()->withInput()->withErrors($item->getErrors());
        }

        return redirect()->route('deployment-waves.show', $wave)
            ->with('success', trans('admin/deployments/general.item_added'));
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

        // Bridge: advancing the stage can flip the real asset's status.
        if ($stage->maps_to_status_id && $deploymentItem->asset_id) {
            $asset = $deploymentItem->asset;
            if ($asset) {
                $asset->status_id = $stage->maps_to_status_id;
                $asset->save();
            }
        }

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

            if ($leavingPlanned && ! $item->order_item_id) {
                $gated++;

                continue;
            }

            $item->stage_id = $stage->id;
            $item->deployed_at = $stage->is_terminal ? now() : $item->deployed_at;
            if (! $item->save()) {
                continue;
            }

            if ($stage->maps_to_status_id && $item->asset_id) {
                $asset = $item->asset;
                if ($asset) {
                    $asset->status_id = $stage->maps_to_status_id;
                    $asset->save();
                }
            }

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
