<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ProcurementReportsController;
use App\Models\CapitalRequestLine;
use App\Models\Requisition;
use App\Services\FiscalYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The capital request without the browser.
 *
 * Multi-year planning runs from agent sessions, so the capital picture and
 * the New Ask lines that shape it are reachable here with the same gates as
 * the capital page: reading needs procurement.view, changing the lines
 * needs the right to create a requisition. The numbers come from the same
 * computation the page renders (ProcurementReportsController::
 * capitalRequestData), never a second copy of it.
 */
class CapitalRequestController extends Controller
{
    /**
     * The year's capital request: envelope, requested, remaining, the
     * ending schedules, every line and the paper it became. No fiscal_year
     * means the current one, as on the page.
     */
    public function show(Request $request, ProcurementReportsController $reports): JsonResponse
    {
        $this->authorize('procurement.view');

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            $reports->capitalRequestPayload($request->query('fiscal_year')),
            null
        ));
    }

    /** The New Ask lines, optionally for one fiscal year. */
    public function linesIndex(Request $request, ProcurementReportsController $reports): JsonResponse
    {
        $this->authorize('procurement.view');

        $fy = $request->filled('fiscal_year')
            ? (FiscalYear::normalize($request->query('fiscal_year')) ?? $request->query('fiscal_year'))
            : null;

        $lines = CapitalRequestLine::query()
            ->when($fy, fn ($q) => $q->where('fiscal_year', $fy))
            ->orderBy('fiscal_year')->orderBy('sort_order')->orderBy('id')
            ->get();

        return response()->json(Helper::formatStandardApiResponse('success', [
            'total' => $lines->count(),
            'rows' => $lines->map(fn (CapitalRequestLine $line) => $reports->capitalRequestLineArray($line))->values(),
        ], null));
    }

    public function linesShow(CapitalRequestLine $line, ProcurementReportsController $reports): JsonResponse
    {
        $this->authorize('procurement.view');

        return response()->json(Helper::formatStandardApiResponse('success', $reports->capitalRequestLineArray($line), null));
    }

    public function linesStore(Request $request, ProcurementReportsController $reports): JsonResponse
    {
        $this->authorize('create', Requisition::class);

        $validated = $this->normalized($request->validate(CapitalRequestLine::rules()));
        $line = CapitalRequestLine::create($validated);

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            $reports->capitalRequestLineArray($line->refresh()),
            trans('admin/purchase-orders/general.capital_line_saved')
        ));
    }

    /** Change any field of a line; omitted fields keep their value. */
    public function linesUpdate(Request $request, CapitalRequestLine $line, ProcurementReportsController $reports): JsonResponse
    {
        $this->authorize('create', Requisition::class);

        $rules = collect(CapitalRequestLine::rules())
            ->map(fn (string $rule) => 'sometimes|'.$rule)
            ->all();

        $line->update($this->normalized($request->validate($rules)));

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            $reports->capitalRequestLineArray($line->refresh()),
            trans('admin/purchase-orders/general.capital_line_saved')
        ));
    }

    public function linesDestroy(CapitalRequestLine $line): JsonResponse
    {
        $this->authorize('create', Requisition::class);

        $line->delete();

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            ['id' => (int) $line->id],
            trans('admin/purchase-orders/general.capital_line_deleted')
        ));
    }

    /**
     * Canonical fiscal-year label, as the page's form stores it, and no
     * explicit null for sort_order (the column defaults to 0).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalized(array $validated): array
    {
        if (isset($validated['fiscal_year'])) {
            $validated['fiscal_year'] = FiscalYear::normalize($validated['fiscal_year']) ?? $validated['fiscal_year'];
        }

        if (array_key_exists('sort_order', $validated) && $validated['sort_order'] === null) {
            unset($validated['sort_order']);
        }

        return $validated;
    }
}
