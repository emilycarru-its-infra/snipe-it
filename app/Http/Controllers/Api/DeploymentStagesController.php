<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\DeploymentStage;
use App\Services\Deployments\DeploymentCatalogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Writes to the deployment stage catalog over the API, with the gates and
 * fields of the admin catalog page. The list stays on
 * DeploymentsController::stages (active stages only); a single stage is
 * read here in full, inactive or not. Order is the sort_order field, as on
 * the page — there is no separate reorder action.
 */
class DeploymentStagesController extends Controller
{
    private const CATALOG = 'stages';

    public function show(DeploymentStage $stage): JsonResponse
    {
        $this->authorize('deployments.view');

        return response()->json(Helper::formatStandardApiResponse('success', $stage, null));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('deployments.edit');

        $stage = new DeploymentStage;
        $stage->fill(DeploymentCatalogs::attributes(self::CATALOG, $request->all()));

        return $this->saved($stage);
    }

    public function update(Request $request, DeploymentStage $stage): JsonResponse
    {
        $this->authorize('deployments.edit');

        $stage->fill(DeploymentCatalogs::attributes(self::CATALOG, $request->all(), partial: true));

        return $this->saved($stage);
    }

    public function destroy(DeploymentStage $stage): JsonResponse
    {
        $this->authorize('deployments.edit');

        if (! DeploymentCatalogs::remove(self::CATALOG, $stage)) {
            return response()->json(Helper::formatStandardApiResponse('success', $stage, trans('admin/deployments/general.catalog_in_use_deactivated')));
        }

        return response()->json(Helper::formatStandardApiResponse('success', null, trans('admin/deployments/general.catalog_deleted')));
    }

    private function saved(DeploymentStage $stage): JsonResponse
    {
        if (! $stage->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $stage->getErrors()));
        }

        return response()->json(Helper::formatStandardApiResponse('success', $stage, trans('admin/deployments/general.catalog_saved')));
    }
}
