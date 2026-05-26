<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\FilterRequest;
use App\Http\Transformers\ContractsTransformer;
use App\Models\Contract;
use App\Models\ContractAttribute;
use App\Models\ContractSerial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContractsController extends Controller
{
    public function index(FilterRequest $request): JsonResponse|array
    {
        $this->authorize('view', Contract::class);

        $contracts = Contract::with('supplier', 'parent', 'adminuser', 'owner')
            ->withCount(['children', 'licenses', 'assets', 'serials'])
            ->withSum('children as children_cost_sum', 'total_cost');

        foreach (['theme', 'product', 'fiscal_year', 'type', 'workflow_status', 'supplier_id', 'gl_code', 'tdx_id', 'parent_contract_id', 'source'] as $field) {
            if ($request->filled($field)) {
                $contracts->where($field, '=', $request->input($field));
            }
        }

        if ($request->filled('is_active')) {
            $contracts->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('umbrellas_only') && filter_var($request->input('umbrellas_only'), FILTER_VALIDATE_BOOLEAN)) {
            $contracts->umbrellas();
        }

        if ($request->filled('exclude_synthesized') && filter_var($request->input('exclude_synthesized'), FILTER_VALIDATE_BOOLEAN)) {
            $contracts->realOnly();
        }

        if ($request->filled('expiring_within_days')) {
            $contracts->expiringWithin((int) $request->input('expiring_within_days'));
        }

        if ($request->filled('filter') || $request->filled('search')) {
            $contracts->TextSearch($request->input('filter') ?: $request->input('search'));
        }

        if ($request->input('deleted') === 'true') {
            $contracts->onlyTrashed();
        }

        $offset = ($request->input('offset') > $contracts->count()) ? $contracts->count() : app('api_offset_value');
        $limit = app('api_limit_value');
        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';

        $allowed_columns = [
            'id', 'name', 'contract_number', 'theme', 'product', 'fiscal_year',
            'type', 'workflow_status', 'is_active', 'start_date', 'end_date',
            'total_cost', 'gl_code', 'tdx_id', 'tdx_modified_date',
            'created_at', 'updated_at',
        ];

        switch ($request->input('sort')) {
            case 'supplier':
                $contracts = $contracts
                    ->leftJoin('suppliers', 'contracts.supplier_id', '=', 'suppliers.id')
                    ->orderBy('suppliers.name', $order)
                    ->select('contracts.*');
                break;
            default:
                $sort = in_array($request->input('sort'), $allowed_columns) ? e($request->input('sort')) : 'end_date';
                $contracts = $contracts->orderBy($sort, $order);
                break;
        }

        $total = $contracts->count();
        $contracts = $contracts->skip($offset)->take($limit)->get();

        return (new ContractsTransformer)->transformContracts($contracts, $total);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Contract::class);

        $contract = new Contract;
        $contract->fill($request->except(['serials', 'attributes', 'license_ids', 'asset_ids']));
        $contract->source = $contract->source ?: 'manual';

        if (! $contract->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $contract->getErrors()));
        }

        $this->syncSidecar($contract, $request);

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new ContractsTransformer)->transformContract($contract->fresh(['supplier', 'parent'])),
            trans('admin/contracts/message.create.success')
        ));
    }

    public function show($id): JsonResponse|array
    {
        $this->authorize('view', Contract::class);

        $contract = Contract::with(['supplier', 'parent', 'children', 'licenses', 'assets', 'serials', 'attributes', 'adminuser', 'owner'])
            ->findOrFail($id);

        return (new ContractsTransformer)->transformContract($contract);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorize('update', Contract::class);

        $contract = Contract::findOrFail($id);
        $contract->fill($request->except(['serials', 'attributes', 'license_ids', 'asset_ids']));

        if (! $contract->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $contract->getErrors()));
        }

        $this->syncSidecar($contract, $request);

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new ContractsTransformer)->transformContract($contract->fresh(['supplier', 'parent'])),
            trans('admin/contracts/message.update.success')
        ));
    }

    public function destroy($id): JsonResponse
    {
        $this->authorize('delete', Contract::class);

        $contract = Contract::findOrFail($id);
        $contract->delete();

        return response()->json(Helper::formatStandardApiResponse('success', null, trans('admin/contracts/message.delete.success')));
    }

    // Upsert keyed by tdx_id — the Azure Function calls this once per
    // TDX contract per sync run. Returns the resulting contract.
    public function upsert(Request $request): JsonResponse
    {
        $this->authorize('create', Contract::class);

        $request->validate([
            'tdx_id' => 'required|integer',
        ]);

        $contract = Contract::withTrashed()->where('tdx_id', $request->input('tdx_id'))->first();

        if ($contract && $contract->trashed()) {
            $contract->restore();
        }

        if (! $contract) {
            $contract = new Contract;
            $contract->tdx_id = $request->input('tdx_id');
            $contract->source = 'tdx';
        }

        $contract->fill($request->except(['serials', 'attributes', 'license_ids', 'asset_ids', 'tdx_id']));
        $contract->source = $contract->source ?: 'tdx';

        if (! $contract->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $contract->getErrors()));
        }

        $this->syncSidecar($contract, $request);

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new ContractsTransformer)->transformContract($contract->fresh(['supplier', 'parent'])),
            trans('admin/contracts/message.upsert.success')
        ));
    }

    // Shared logic for storing the dependent collections that ride
    // alongside a contract payload: serials extracted from TDX
    // descriptions, M:N license/asset associations, and the k/v
    // sidecar for un-promoted TDX custom attributes.
    private function syncSidecar(Contract $contract, Request $request): void
    {
        DB::transaction(function () use ($contract, $request) {
            if ($request->has('serials')) {
                $contract->serials()->delete();
                foreach ((array) $request->input('serials', []) as $row) {
                    if (is_string($row)) {
                        $row = ['serial' => $row];
                    }
                    if (empty($row['serial'])) {
                        continue;
                    }
                    ContractSerial::create([
                        'contract_id' => $contract->id,
                        'serial'      => $row['serial'],
                        'source'      => $row['source'] ?? 'manual',
                        'notes'       => $row['notes'] ?? null,
                    ]);
                }
            }

            if ($request->has('attributes')) {
                $contract->attributes()->delete();
                foreach ((array) $request->input('attributes', []) as $row) {
                    if (empty($row['name'])) {
                        continue;
                    }
                    ContractAttribute::create([
                        'contract_id' => $contract->id,
                        'name'        => $row['name'],
                        'value'       => $row['value'] ?? null,
                    ]);
                }
            }

            if ($request->has('license_ids')) {
                $sync = [];
                foreach ((array) $request->input('license_ids', []) as $row) {
                    if (is_numeric($row)) {
                        $sync[(int) $row] = [];
                    } elseif (is_array($row) && isset($row['id'])) {
                        $sync[(int) $row['id']] = array_intersect_key($row, array_flip(['seats_covered', 'valid_from', 'valid_to', 'notes']));
                    }
                }
                $contract->licenses()->sync($sync);
            }

            if ($request->has('asset_ids')) {
                $sync = [];
                foreach ((array) $request->input('asset_ids', []) as $row) {
                    if (is_numeric($row)) {
                        $sync[(int) $row] = [];
                    } elseif (is_array($row) && isset($row['id'])) {
                        $sync[(int) $row['id']] = array_intersect_key($row, array_flip(['valid_from', 'valid_to', 'notes']));
                    }
                }
                $contract->assets()->sync($sync);
            }
        });
    }
}
