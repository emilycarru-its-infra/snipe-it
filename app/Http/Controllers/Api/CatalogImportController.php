<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Services\AppleStoreSync;
use App\Services\CatalogPriceListImport;
use App\Services\CatalogRelink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Headless ingest for reseller price lists.
 *
 * This exists because the deployed App Service containers run no shell, so
 * `catalog:import` cannot reach dev or production — an upload over the API
 * is the only way to refresh the catalog on a real environment without
 * clicking through the importer UI.
 *
 * Takes the reseller's workbook as-is (.xlsx or .csv) plus the run metadata
 * that isn't in the file: which supplier it came from, what to call the
 * price list, when it was quoted.
 */
class CatalogImportController extends Controller
{
    public function store(Request $request, CatalogPriceListImport $importer): JsonResponse
    {
        $this->authorize('create', CatalogItem::class);

        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,csv,txt|max:20480',
            'supplier' => 'nullable|string|max:191',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'source' => 'nullable|string|max:191',
            'quoted_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'deactivate_missing' => 'nullable|boolean',
            'dry_run' => 'nullable|boolean',
            'show_in_store' => 'nullable|boolean',
        ]);

        $file = $request->file('file');

        // Read straight off the upload's temp path — the price list is
        // reference data we re-derive on every import, so there is nothing
        // worth keeping the original file around for. The temp path has no
        // extension, so a CSV upload would fall into the XLSX reader and
        // die on "not a zip archive" — give the reader a correctly-named
        // link to route by.
        $path = $file->getRealPath();
        if (strtolower($file->getClientOriginalExtension()) !== strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            $typed = $path.'.'.strtolower($file->getClientOriginalExtension() ?: 'csv');
            copy($path, $typed);
            $path = $typed;
        }

        $result = $importer->importFile($path, [
            'supplier' => $validated['supplier'] ?? null,
            'supplier_id' => $validated['supplier_id'] ?? null,
            'source' => $validated['source'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'quoted_at' => $validated['quoted_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'deactivate_missing' => (bool) ($validated['deactivate_missing'] ?? false),
            'dry_run' => (bool) ($validated['dry_run'] ?? false),
            'show_in_store' => (bool) ($validated['show_in_store'] ?? false),
            'created_by' => auth()->id(),
        ]);

        if ($result['rows'] === 0) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, trans('admin/purchase-orders/general.catalog_import_no_rows')),
                422
            );
        }

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            $result,
            trans('admin/purchase-orders/general.catalog_import_result', [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
            ])
        ));
    }

    /**
     * Re-derive specs and asset-model links across the whole catalog —
     * the headless twin of `catalog:relink`, for the same no-shell reason
     * as the import above.
     */
    public function relink(Request $request, CatalogRelink $relinker): JsonResponse
    {
        $this->authorize('update', CatalogItem::class);

        $stats = $relinker->relink((bool) $request->boolean('rematch_linked'));

        return response()->json(Helper::formatStandardApiResponse('success', $stats, null));
    }

    /**
     * Refresh prices and specs from apple.com/ca on demand. The weekly
     * scheduler run does this unattended; the endpoint exists for "the
     * new MacBooks just dropped" moments.
     */
    public function appleSync(Request $request, AppleStoreSync $sync): JsonResponse
    {
        $this->authorize('update', CatalogItem::class);

        $stats = $sync->sync([], (bool) $request->boolean('dry_run'));

        return response()->json(Helper::formatStandardApiResponse('success', $stats, null));
    }
}
