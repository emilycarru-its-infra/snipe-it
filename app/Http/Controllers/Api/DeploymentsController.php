<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentType;
use App\Models\DeploymentWave;
use App\Services\Deployments\DecommissionLane;
use App\Services\Deployments\RefreshForecast;
use Illuminate\Http\Request;

/**
 * The deployments module over the API — everything the board can do, a
 * token can do: list and shape waves, move devices through stages (the
 * same Planned→Ordered gate as the UI), group cohorts, read the forecast
 * and pull candidates onto waves, and read the decommissioning lane. This
 * is what lets the planning itself run agentically.
 *
 * Read endpoints require deployments.view, writes deployments.edit —
 * identical to the web module.
 */
class DeploymentsController extends Controller
{
    /*
    |----------------------------------------------------------------------
    | Catalogs
    |----------------------------------------------------------------------
    */

    public function stages()
    {
        $this->authorize('deployments.view');

        return response()->json(Helper::formatStandardApiResponse('success', DeploymentStage::active()->ordered()->get(
            ['id', 'name', 'slug', 'color', 'sort_order', 'is_terminal', 'maps_to_status_id']
        ), null));
    }

    public function types()
    {
        $this->authorize('deployments.view');

        return response()->json(Helper::formatStandardApiResponse('success', DeploymentType::active()->ordered()->get(
            ['id', 'name', 'slug', 'color', 'moves_devices', 'sort_order']
        ), null));
    }

    /*
    |----------------------------------------------------------------------
    | Waves
    |----------------------------------------------------------------------
    */

    public function wavesIndex(Request $request)
    {
        $this->authorize('deployments.view');

        $waves = DeploymentWave::query()
            ->withCount('items')
            ->when($request->filled('fiscal_year'), fn ($q) => $q->where('fiscal_year', RefreshForecast::normalizeFy($request->query('fiscal_year'))))
            ->ordered()
            ->get()
            ->map(fn ($wave) => $this->waveJson($wave));

        return response()->json(Helper::formatStandardApiResponse('success', $waves, null));
    }

    public function wavesShow(DeploymentWave $wave)
    {
        $this->authorize('deployments.view');

        $wave->load(['items.stage', 'items.asset', 'items.replacesAsset', 'items.model', 'items.orderItem']);

        $payload = $this->waveJson($wave);
        $payload['items'] = $wave->items->map(fn ($item) => $this->itemJson($item));

        return response()->json(Helper::formatStandardApiResponse('success', $payload, null));
    }

    public function wavesStore(Request $request)
    {
        $this->authorize('deployments.edit');

        $wave = new DeploymentWave;
        $wave->fill($request->only([
            'name', 'fiscal_year', 'wave_state', 'deployment_type_id', 'location_id',
            'storage_location_id', 'owner_id', 'purchase_order_id', 'form_key', 'color', 'notes',
            'arrival_window_start', 'arrival_window_end', 'target_start_date', 'target_end_date',
        ]));
        $wave->fiscal_year = RefreshForecast::normalizeFy($wave->fiscal_year) ?: $wave->fiscal_year;
        $wave->wave_state = $wave->wave_state ?: 'planned';
        $wave->created_by = auth()->id();

        if (! $wave->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $wave->getErrors()), 422);
        }

        return response()->json(Helper::formatStandardApiResponse('success', $this->waveJson($wave->fresh()), trans('admin/deployments/general.created')));
    }

    public function wavesUpdate(Request $request, DeploymentWave $wave)
    {
        $this->authorize('deployments.edit');

        $wave->fill($request->only([
            'name', 'fiscal_year', 'wave_state', 'deployment_type_id', 'location_id',
            'storage_location_id', 'owner_id', 'purchase_order_id', 'form_key', 'color', 'notes',
            'arrival_window_start', 'arrival_window_end', 'target_start_date', 'target_end_date',
        ]));

        if (! $wave->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $wave->getErrors()), 422);
        }

        return response()->json(Helper::formatStandardApiResponse('success', $this->waveJson($wave->fresh()), trans('admin/deployments/general.updated')));
    }

    public function wavesDestroy(DeploymentWave $wave)
    {
        $this->authorize('deployments.edit');

        $wave->delete();

        return response()->json(Helper::formatStandardApiResponse('success', null, trans('admin/deployments/general.deleted')));
    }

    /*
    |----------------------------------------------------------------------
    | Items (devices on waves)
    |----------------------------------------------------------------------
    */

    public function itemsStore(Request $request, DeploymentWave $wave)
    {
        $this->authorize('deployments.edit');

        $attributes = $request->only([
            'asset_id', 'replaces_asset_id', 'order_item_id', 'model_id', 'stage_id', 'group_label',
            'assigned_user_id', 'assigned_tech_id', 'storage_location_id', 'target_deploy_date', 'notes',
        ]);
        $attributes['wave_id'] = $wave->id;
        $attributes['stage_id'] = ($attributes['stage_id'] ?? null)
            ?: DeploymentStage::where('slug', 'planned')->value('id');

        // A device is on a wave once. Posting it again fills in what the
        // existing row is missing and returns that row, so an integration
        // that re-posts its inventory cannot silently double the wave.
        if ($existing = DeploymentItem::onWave($wave->id, (int) $request->input('asset_id') ?: null)) {
            if (! $existing->absorb($attributes)) {
                return response()->json(Helper::formatStandardApiResponse('error', null, $existing->getErrors()), 422);
            }

            return response()->json(Helper::formatStandardApiResponse('success', $this->itemJson($existing->fresh(['stage', 'asset', 'replacesAsset', 'model'])), trans('admin/deployments/general.item_merged')));
        }

        $item = new DeploymentItem($attributes);

        if (! $item->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $item->getErrors()), 422);
        }

        return response()->json(Helper::formatStandardApiResponse('success', $this->itemJson($item->fresh(['stage', 'asset', 'replacesAsset', 'model'])), trans('admin/deployments/general.item_added')));
    }

    public function itemsUpdate(Request $request, DeploymentItem $item)
    {
        $this->authorize('deployments.edit');

        // Stage moves through the generic update respect the same gate as
        // the board: leaving Planned requires a linked order line — unless
        // the device already exists (stock-covered, relocation, exhibit),
        // which has nothing to order.
        if ($request->filled('stage_id')) {
            $target = DeploymentStage::find((int) $request->input('stage_id'));
            $fromPlanned = ! $item->stage || $item->stage->slug === 'planned';
            $orderItemId = $request->input('order_item_id', $item->order_item_id);
            $assetId = $request->input('asset_id', $item->asset_id);
            if ($target && $fromPlanned && $target->slug !== 'planned' && ! $orderItemId && ! $assetId) {
                return response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/deployments/general.bulk_gated', ['count' => 1])), 422);
            }
        }

        $item->fill($request->only([
            'asset_id', 'replaces_asset_id', 'order_item_id', 'model_id', 'stage_id', 'group_label',
            'assigned_user_id', 'assigned_tech_id', 'storage_location_id', 'target_deploy_date', 'notes',
        ]));

        // Moving this row onto a device the wave already tracks would make
        // the twin the unique key forbids; say so rather than fail at the
        // database.
        if ($item->isDirty('asset_id') && $item->conflictsOnWave($item->asset_id)) {
            return response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/deployments/general.item_duplicate_asset')), 422);
        }

        $stage = null;
        if ($item->isDirty('stage_id')) {
            $stage = DeploymentStage::find($item->stage_id);
            if ($stage?->is_terminal) {
                $item->deployed_at = now();
            }
        }

        if (! $item->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $item->getErrors()), 422);
        }

        if ($stage) {
            $item->applyStageEffects($stage);
        }

        return response()->json(Helper::formatStandardApiResponse('success', $this->itemJson($item->fresh(['stage', 'asset', 'replacesAsset', 'model'])), trans('admin/deployments/general.item_updated')));
    }

    public function itemsDestroy(DeploymentItem $item)
    {
        $this->authorize('deployments.edit');

        $item->delete();

        return response()->json(Helper::formatStandardApiResponse('success', null, trans('admin/deployments/general.item_deleted')));
    }

    /*
    |----------------------------------------------------------------------
    | Forecast + decommissioning
    |----------------------------------------------------------------------
    */

    public function forecast(Request $request)
    {
        $this->authorize('deployments.view');

        $fy = RefreshForecast::normalizeFy($request->query('fiscal_year'));
        $candidates = (new RefreshForecast)->forFiscalYear($fy)->map(fn ($asset) => [
            'id' => $asset->id,
            'asset_tag' => $asset->asset_tag,
            'name' => $asset->name,
            'serial' => $asset->serial,
            'model' => $asset->model?->name,
            'refresh_reason' => $asset->refresh_reason,
            'due' => $asset->source_date,
            'status' => $asset->status?->name,
            'location' => $asset->location?->name,
            'lease_contract_id' => $asset->lease_contract_id,
            'lease_decision' => $asset->lease_decision_label,
            'lease_decision_note' => $asset->lease_decision_note,
        ]);

        return response()->json(Helper::formatStandardApiResponse('success', ['fiscal_year' => $fy, 'candidates' => $candidates], null));
    }

    public function decommission(Request $request)
    {
        $this->authorize('deployments.view');

        $fy = RefreshForecast::normalizeFy($request->query('fiscal_year'));

        return response()->json(Helper::formatStandardApiResponse('success', (new DecommissionLane)->build($fy), null));
    }

    /*
    |----------------------------------------------------------------------
    | JSON shapes
    |----------------------------------------------------------------------
    */

    private function waveJson(DeploymentWave $wave): array
    {
        return [
            'id' => $wave->id,
            'name' => $wave->name,
            'fiscal_year' => $wave->fiscal_year,
            'wave_state' => $wave->wave_state,
            'form_key' => $wave->form_key,
            'type' => $wave->typeLabel(),
            'deployment_type_id' => $wave->deployment_type_id,
            'items_count' => $wave->items_count ?? $wave->items()->count(),
            'arrival_window_start' => optional($wave->arrival_window_start)->toDateString(),
            'arrival_window_end' => optional($wave->arrival_window_end)->toDateString(),
            'target_start_date' => optional($wave->target_start_date)->toDateString(),
            'target_end_date' => optional($wave->target_end_date)->toDateString(),
            'notes' => $wave->notes,
        ];
    }

    private function itemJson(DeploymentItem $item): array
    {
        return [
            'id' => $item->id,
            'wave_id' => $item->wave_id,
            'stage_id' => $item->stage_id,
            'stage' => $item->stage?->slug,
            'group_label' => $item->group_label,
            'asset_id' => $item->asset_id,
            'asset_tag' => $item->asset?->asset_tag,
            'replaces_asset_id' => $item->replaces_asset_id,
            'replaces_asset_tag' => $item->replacesAsset?->asset_tag,
            'order_item_id' => $item->order_item_id,
            'model' => $item->model?->name,
            'assigned_user_id' => $item->assigned_user_id,
            'assigned_tech_id' => $item->assigned_tech_id,
            'storage_location_id' => $item->storage_location_id,
            'target_deploy_date' => optional($item->target_deploy_date)->toDateString(),
            'deployed_at' => optional($item->deployed_at)->toDateString(),
            'notes' => $item->notes,
        ];
    }
}
