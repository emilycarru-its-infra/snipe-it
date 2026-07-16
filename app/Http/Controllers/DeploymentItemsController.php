<?php

namespace App\Http\Controllers;

use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentWave;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Per-device rows on a deployment wave board. `store` adds a blank/manual
 * item; `updateStage` advances a device through the pipeline (and, when the
 * target stage maps to a Snipe status, flips the linked asset's status);
 * `update` edits the row fields; `destroy` removes it. Authorization
 * reuses the Order policy, like the wave + exhibit boards.
 */
class DeploymentItemsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

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
        $this->authorize('update', Order::class);

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

    public function update(Request $request, DeploymentItem $deploymentItem): RedirectResponse
    {
        $this->authorize('update', Order::class);

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
        $this->authorize('delete', Order::class);

        $waveId = $deploymentItem->wave_id;
        $deploymentItem->delete();

        return redirect()->route('deployment-waves.show', $waveId)
            ->with('success', trans('admin/deployments/general.item_deleted'));
    }
}
