<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Services\CatalogPriceListImport;
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
        // worth keeping the original file around for.
        $result = $importer->importFile($file->getRealPath(), [
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
}
