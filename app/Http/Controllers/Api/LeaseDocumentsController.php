<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Leasing\LeaseDocumentParser;
use App\Services\Leasing\ScheduleIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lease document ingestion for agent sessions and automations: POST a
 * schedule agreement, certificate of acceptance, or Exhibit A draft and get
 * the parsed fields back. Add commit=1 to write the result through
 * ScheduleIntake — the same path the web drop zone uses.
 *
 * Scalar overrides (schedule_ref, term dates, amounts …) may be sent
 * alongside the file to correct anything the parser misread before commit.
 */
class LeaseDocumentsController extends Controller
{
    public function store(Request $request, LeaseDocumentParser $parser, ScheduleIntake $intake): JsonResponse
    {
        $this->authorize('update', Order::class);

        $request->validate([
            'document' => 'required|file|mimes:pdf,xlsx|max:'.Helper::file_upload_max_size(),
            'commit' => 'nullable|boolean',
            'schedule_ref' => 'nullable|string|max:32',
            'lessor' => 'nullable|string|max:191',
            'dated_as_of' => 'nullable|date',
            'term_start' => 'nullable|date',
            'term_end' => 'nullable|date',
            'term_months' => 'nullable|integer|min:1|max:240',
            'yearly_rental' => 'nullable|numeric|min:0',
            'stip_loss_value' => 'nullable|numeric|min:0',
            'cost_cap' => 'nullable|numeric|min:0',
            'lease_type' => 'nullable|string|max:64',
        ]);

        $document = $request->file('document');
        $parsed = $parser->parse($document->getRealPath(), $document->getClientOriginalName());

        if (($parsed['type'] ?? null) === null) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $parsed['error']), 422);
        }

        $overrides = collect($request->only([
            'schedule_ref', 'lessor', 'dated_as_of', 'term_start', 'term_end',
            'term_months', 'yearly_rental', 'stip_loss_value', 'cost_cap', 'lease_type',
        ]))->filter(fn ($v) => $v !== null && $v !== '')->all();
        $parsed = array_merge($parsed, $overrides);

        if (! $request->boolean('commit')) {
            return response()->json(Helper::formatStandardApiResponse('success', [
                'parsed' => $parsed,
                'committed' => false,
            ], null));
        }

        try {
            $result = $intake->apply($parsed, $document);
        } catch (\RuntimeException $e) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $e->getMessage()), 422);
        }

        return response()->json(Helper::formatStandardApiResponse('success', [
            'committed' => true,
            'action' => $result['action'],
            'schedule' => [
                'id' => $result['schedule']->id,
                'schedule_ref' => $result['schedule']->schedule_ref,
                'lifecycle_stage' => $result['schedule']->lifecycle_stage,
            ],
            'contract' => $result['contract'] ? [
                'id' => $result['contract']->id,
                'name' => $result['contract']->name,
                'schedule_number' => $result['contract']->schedule_number,
                'total_cost' => $result['contract']->total_cost,
            ] : null,
            'warnings' => $result['warnings'],
        ], null));
    }
}
