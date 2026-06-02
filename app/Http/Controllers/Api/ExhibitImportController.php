<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exhibit;
use App\Services\Exhibits\ExhibitCsvImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token-authed endpoint for backfilling exhibit projects from a year's
 * Grad Show CSV export. Reuses the header-driven ExhibitCsvImporter, so
 * the historical layouts import the same as the in-app upload — but this
 * one is callable with the Snipe API token (no SSO session needed), which
 * is how the multi-year backfill is driven. Guarded by the v1 API token
 * middleware; the uploaded file is read from its temp path and never
 * persisted.
 */
class ExhibitImportController extends Controller
{
    public function import(Request $request, ExhibitCsvImporter $importer): JsonResponse
    {
        $request->validate([
            'exhibit_id' => 'required|exists:exhibits,id',
            'year' => 'required|integer|min:2000|max:2100',
            'file' => 'required|file',
        ]);

        $exhibit = Exhibit::findOrFail($request->input('exhibit_id'));
        $year = (int) $request->input('year');

        try {
            $summary = $importer->import($request->file('file')->getRealPath(), $exhibit, $year);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'messages' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'exhibit' => $exhibit->name,
            'year' => $year,
            'imported' => $summary['imported'],
            'skipped' => $summary['skipped'],
        ]);
    }
}
