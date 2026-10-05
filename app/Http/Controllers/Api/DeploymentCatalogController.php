<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\DeploymentStage;
use App\Models\DeploymentType;
use App\Services\Deployments\DeploymentCatalogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Writes to the deployment catalogs (wave types, per-device stages) over
 * the API, with the gates and fields of the admin catalog page. Each route
 * fixes its catalog as a route default. The lists stay on
 * DeploymentsController::stages and ::types (active entries only); a single
 * entry is read here in full, inactive or not. Order is the sort_order
 * field, as on the page — there is no separate reorder action.
 */
class DeploymentCatalogController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $catalog = $this->catalog();
        $this->authorize('deployments.view');

        return response()->json(Helper::formatStandardApiResponse('success', $this->find($catalog, $id), null));
    }

    public function store(Request $request): JsonResponse
    {
        $catalog = $this->catalog();
        $this->authorize('deployments.edit');
        [$class] = DeploymentCatalogs::resolve($catalog);

        $item = new $class;
        $item->fill(DeploymentCatalogs::attributes($catalog, $request->all()));

        return $this->saved($item);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $catalog = $this->catalog();
        $this->authorize('deployments.edit');

        $item = $this->find($catalog, $id);
        $item->fill(DeploymentCatalogs::attributes($catalog, $request->all(), partial: true));

        return $this->saved($item);
    }

    public function destroy(int $id): JsonResponse
    {
        $catalog = $this->catalog();
        $this->authorize('deployments.edit');

        $item = $this->find($catalog, $id);

        if (! DeploymentCatalogs::remove($catalog, $item)) {
            return response()->json(Helper::formatStandardApiResponse('success', $item, trans('admin/deployments/general.catalog_in_use_deactivated')));
        }

        return response()->json(Helper::formatStandardApiResponse('success', null, trans('admin/deployments/general.catalog_deleted')));
    }

    /** The catalog this route serves, fixed by its route default. */
    private function catalog(): string
    {
        return (string) request()->route('catalog');
    }

    private function find(string $catalog, int $id): DeploymentType|DeploymentStage
    {
        [$class] = DeploymentCatalogs::resolve($catalog);

        return $class::findOrFail($id);
    }

    private function saved(DeploymentType|DeploymentStage $item): JsonResponse
    {
        if (! $item->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $item->getErrors()));
        }

        return response()->json(Helper::formatStandardApiResponse('success', $item, trans('admin/deployments/general.catalog_saved')));
    }
}
