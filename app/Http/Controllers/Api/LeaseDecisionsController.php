<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\FilterRequest;
use App\Http\Transformers\LeaseDecisionsTransformer;
use App\Models\LeaseDecision;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaseDecisionsController extends Controller
{
    /**
     * Display a listing of lease decisions.
     */
    public function index(FilterRequest $request): array
    {
        $this->authorize('view', Order::class);

        $allowed_columns = [
            'id',
            'contract_reference',
            'decision_type',
            'decision_date',
            'amount',
            'status',
            'created_at',
        ];

        $decisions = LeaseDecision::with('adminuser');

        if ($request->filled('filter') || $request->filled('search')) {
            $decisions->TextSearch($request->input('filter') ? $request->input('filter') : $request->input('search'));
        }

        if ($request->filled('status')) {
            $decisions->where('status', '=', $request->input('status'));
        }

        if ($request->filled('decision_type')) {
            $decisions->where('decision_type', '=', $request->input('decision_type'));
        }

        if ($request->filled('contract_reference')) {
            $decisions->where('contract_reference', '=', $request->input('contract_reference'));
        }

        $offset = ($request->input('offset') > $decisions->count()) ? $decisions->count() : app('api_offset_value');
        $limit = app('api_limit_value');

        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';
        $sort = in_array($request->input('sort'), $allowed_columns) ? $request->input('sort') : 'created_at';

        $decisions->orderBy($sort, $order);

        $total = $decisions->count();
        $decisions = $decisions->skip($offset)->take($limit)->get();

        return (new LeaseDecisionsTransformer)->transformLeaseDecisions($decisions, $total);
    }

    /**
     * The fields a decision accepts over the API. The same ones the
     * decision form writes, plus the per-device target and the deferral
     * year the model already carries.
     *
     * @return array<string, string>
     */
    private function rules(): array
    {
        return [
            'contract_reference' => 'required|string|max:191',
            'asset_id' => 'nullable|integer|exists:assets,id',
            'decision_type' => 'nullable|string|in:'.implode(',', LeaseDecision::DECISION_TYPES),
            'decision_date' => 'nullable|date',
            'deferred_to_fy' => 'nullable|string|max:191',
            'amount' => 'nullable|numeric',
            'status' => 'nullable|string|in:'.implode(',', LeaseDecision::STATUSES),
            'notes' => 'nullable|string|max:65535',
        ];
    }

    public function show(int $lease_decision_id): JsonResponse
    {
        $this->authorize('view', Order::class);

        $decision = LeaseDecision::findOrFail($lease_decision_id);

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new LeaseDecisionsTransformer)->transformLeaseDecision($decision),
            null
        ));
    }

    /**
     * Log a decision. An omitted type and status take the form's defaults
     * (a pending buyout); an explicit null type is a plan note, which the
     * schedule pages read separately from decisions.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Order::class);

        $validated = $request->validate($this->rules());

        $decision = new LeaseDecision;
        $decision->fill($validated);
        if (! $request->has('decision_type')) {
            $decision->decision_type = 'buyout';
        }
        $decision->status = $validated['status'] ?? 'pending';
        $decision->created_by = auth()->id();

        if (! $decision->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $decision->getErrors()), 422);
        }

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new LeaseDecisionsTransformer)->transformLeaseDecision($decision),
            trans('admin/lease-decisions/message.create.success')
        ));
    }

    /** Change a decision; omitted fields keep their value. */
    public function update(Request $request, int $lease_decision_id): JsonResponse
    {
        $this->authorize('update', Order::class);

        $decision = LeaseDecision::findOrFail($lease_decision_id);

        $rules = collect($this->rules())->map(fn (string $rule) => 'sometimes|'.$rule)->all();
        $validated = $request->validate($rules);

        $decision->fill($validated);
        if (array_key_exists('status', $validated) && $validated['status'] === null) {
            $decision->status = 'pending';
        }

        if (! $decision->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $decision->getErrors()), 422);
        }

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new LeaseDecisionsTransformer)->transformLeaseDecision($decision),
            trans('admin/lease-decisions/message.update.success')
        ));
    }

    public function destroy(int $lease_decision_id): JsonResponse
    {
        $this->authorize('delete', Order::class);

        $decision = LeaseDecision::findOrFail($lease_decision_id);
        $decision->delete();

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            ['id' => (int) $decision->id],
            trans('admin/lease-decisions/message.delete.success')
        ));
    }
}
