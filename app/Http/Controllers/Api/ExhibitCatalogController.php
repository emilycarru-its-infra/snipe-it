<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Exhibit;
use App\Models\ExhibitProjectType;
use App\Models\ExhibitStatus;
use App\Models\Order;
use App\Services\Exhibits\ExhibitCatalogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The exhibit catalogs (exhibits, project types, statuses) over the API,
 * dispatched on {catalog} like the exhibit-config pages and under the same
 * Order policy. A PATCH changes only the fields it sends; a delete of an
 * entry still in use deactivates it, as the page does.
 */
class ExhibitCatalogController extends Controller
{
    public function index(string $catalog): JsonResponse
    {
        $this->authorize('view', Order::class);
        [$class] = ExhibitCatalogs::resolve($catalog);

        $items = $class::orderBy('sort_order')->orderBy('name')->get();

        return response()->json(Helper::formatStandardApiResponse('success', [
            'total' => $items->count(),
            'rows' => $items,
        ], null));
    }

    public function show(string $catalog, int $id): JsonResponse
    {
        $this->authorize('view', Order::class);
        [$class] = ExhibitCatalogs::resolve($catalog);

        return response()->json(Helper::formatStandardApiResponse('success', $class::findOrFail($id), null));
    }

    public function store(Request $request, string $catalog): JsonResponse
    {
        $this->authorize('update', Order::class);
        [$class] = ExhibitCatalogs::resolve($catalog);

        $item = new $class;
        $item->fill(ExhibitCatalogs::attributes($request->all()));

        return $this->saved($item);
    }

    public function update(Request $request, string $catalog, int $id): JsonResponse
    {
        $this->authorize('update', Order::class);
        [$class] = ExhibitCatalogs::resolve($catalog);

        $item = $class::findOrFail($id);
        $item->fill(ExhibitCatalogs::attributes($request->all(), partial: true));

        return $this->saved($item);
    }

    public function destroy(string $catalog, int $id): JsonResponse
    {
        $this->authorize('update', Order::class);
        [$class] = ExhibitCatalogs::resolve($catalog);

        $item = $class::findOrFail($id);

        if (! ExhibitCatalogs::remove($catalog, $item)) {
            return response()->json(Helper::formatStandardApiResponse('success', $item, trans('admin/exhibit-projects/general.catalog_in_use_deactivated')));
        }

        return response()->json(Helper::formatStandardApiResponse('success', null, trans('admin/exhibit-projects/general.catalog_deleted')));
    }

    private function saved(Exhibit|ExhibitProjectType|ExhibitStatus $item): JsonResponse
    {
        if (! $item->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $item->getErrors()));
        }

        return response()->json(Helper::formatStandardApiResponse('success', $item, trans('admin/exhibit-projects/general.catalog_saved')));
    }
}
