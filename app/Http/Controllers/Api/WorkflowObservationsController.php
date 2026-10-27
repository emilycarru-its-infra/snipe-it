<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Transformers\AgreementObservationsTransformer;
use App\Http\Transformers\LeaseObservationsTransformer;
use App\Models\Asset;
use App\Models\Consumable;
use App\Models\ConsumableTransaction;
use App\Models\ExhibitProject;
use App\Models\Order;
use App\Models\UserAgreement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Read-only source facts; no recipient lookup, financial policy or workflow transition. */
class WorkflowObservationsController extends Controller
{
    public function leases(Request $request): JsonResponse
    {
        $this->authorize('view', Asset::class);

        // Asset's company and soft-delete scopes remain in force. Presence of stored facts
        // does not assert that a lease is currently open or that a decommission was a return.
        $query = Asset::query()->where(function (Builder $query) {
            $query->whereNotNull('lessor_id')
                ->orWhereRaw('LOWER(ownership_type) LIKE ?', ['%lease%'])
                ->orWhere(function (Builder $contracts) {
                    $contracts->whereNotNull('lease_contract_id')->where('lease_contract_id', '!=', '');
                })
                ->orWhere(function (Builder $contracts) {
                    $contracts->whereNotNull('lease_contract_name')->where('lease_contract_name', '!=', '');
                })
                ->orWhereNotNull('lease_end_date')->orWhereNotNull('lease_rent')->orWhereNotNull('buyout_cost');
        })->select(LeaseObservationsTransformer::FIELDS);
        $transformer = new LeaseObservationsTransformer;

        return $this->page($request, $query, fn (Asset $row) => $transformer->transform($row), maxOffset: 20000);
    }

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

    public function userAgreements(Request $request): JsonResponse
    {
        $this->authorize('view', Order::class);

        $query = UserAgreement::query()->select([
            ...AgreementObservationsTransformer::FIELDS, 'pdf_path', 'signed_pdf_path',
        ]);
        $transformer = new AgreementObservationsTransformer;

        return $this->page($request, $query, fn (UserAgreement $row) => $transformer->transform($row));
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
    private function page(Request $request, Builder $query, callable $project, int $maxOffset = 10000): JsonResponse
    {
        $validation = Validator::make($request->query(), [
            'limit' => 'sometimes|integer|min:1|max:100',
            'offset' => 'sometimes|integer|min:0|max:'.$maxOffset,
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
