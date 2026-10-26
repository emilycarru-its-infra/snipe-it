<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consumable;
use App\Models\ConsumableTransaction;
use App\Models\ExhibitProject;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Read-only source facts; no recipient lookup, financial policy or workflow transition. */
class WorkflowObservationsController extends Controller
{
    public function exhibitProjects(Request $request): JsonResponse
    {
        $this->authorize('view', Order::class);

        $fields = ['id', 'exhibit_id', 'year', 'user_id', 'student_name', 'asset_id',
            'status_id', 'project_type_id', 'project_details', 'requested_device',
            'peripherals', 'submitted_file', 'approved', 'tdx_id', 'notes'];

        return $this->page($request, ExhibitProject::query()->select($fields), function (ExhibitProject $row) use ($fields) {
            $facts = $row->only($fields);
            foreach (['id', 'exhibit_id', 'user_id', 'asset_id', 'status_id', 'project_type_id'] as $key) {
                $facts[$key] = $facts[$key] === null ? null : (string) $facts[$key];
            }

            return $facts;
        });
    }

    public function consumableTransactions(Request $request): JsonResponse
    {
        $this->authorize('view', Consumable::class);

        // The relation retains CompanyableScope. Deleted parents are outside this source scope.
        $query = ConsumableTransaction::query()
            ->whereHas('consumable', fn (Builder $parent) => $parent->whereNull('consumables.deleted_at'))
            ->select(['id', 'consumable_id', 'asset_id', 'gl_code', 'quantity', 'unit_cost',
                'total_cost', 'transaction_date', 'fiscal_year', 'status', 'notes']);

        return $this->page($request, $query, fn (ConsumableTransaction $row) => [
            'id' => (string) $row->id,
            'consumable_id' => (string) $row->consumable_id,
            'asset_id' => (string) $row->asset_id,
            'gl_code' => $row->gl_code,
            'quantity' => $row->quantity,
            'unit_cost' => $row->unit_cost,
            'total_cost' => $row->total_cost,
            'transaction_date' => $row->transaction_date?->format('Y-m-d'),
            'fiscal_year' => $row->fiscal_year,
            'status' => $row->status,
            'notes' => $row->notes,
        ]);
    }

    /** Each page is a fresh observation; no cross-page transactional snapshot is asserted. */
    private function page(Request $request, Builder $query, callable $project): JsonResponse
    {
        $validation = Validator::make($request->query(), [
            'limit' => 'sometimes|integer|min:1|max:100',
            'offset' => 'sometimes|integer|min:0|max:10000',
        ]);
        if ($validation->fails()) {
            return response()->json(['message' => 'Invalid observation paging.'], 422, ['Cache-Control' => 'private, no-store']);
        }
        $params = $validation->validated();
        $total = (clone $query)->count();
        $rows = $query->orderBy('id')->offset($params['offset'] ?? 0)->limit($params['limit'] ?? 100)
            ->get()->map($project)->values();

        return response()->json(['total' => $total, 'rows' => $rows], 200, ['Cache-Control' => 'private, no-store']);
    }
}
