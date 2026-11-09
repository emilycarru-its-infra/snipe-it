<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\CsiSchedule;
use App\Models\ProcurementSetting;
use App\Models\Requisition;
use App\Models\SupplierAccount;
use App\Services\SupplierAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The supplier accounts and the lease-schedule cadence, editable.
 *
 * Both used to be PHP constants: changing an account number, or moving the
 * anchor when a new schedule pair opened, meant a pull request and a deploy
 * for a fact that lives on the supplier's timetable. A new pair opens every
 * three months, so the constant was guaranteed to go stale four times a
 * year.
 */
class ProcurementConfigController extends Controller
{
    public function accounts(): JsonResponse
    {
        $this->authorize('view', Requisition::class);

        return response()->json([
            'total' => SupplierAccount::count(),
            'rows' => SupplierAccount::orderBy('sort')->orderBy('key')->get()->map(fn (SupplierAccount $account) => [
                'id' => $account->id,
                'supplier_id' => $account->supplier_id,
                'supplier' => $account->supplier?->name,
                'key' => $account->key,
                'number' => $account->number,
                'purpose' => $account->purpose,
                'kind' => $account->kind,
                'scope' => $account->scope,
                'payee' => $account->payee,
                'schedule_type' => $account->schedule_type,
                'needs_schedule' => $account->payee === 'csi',
                'active' => $account->active,
            ])->all(),
        ]);
    }

    /**
     * Create or update one account by its key. The key is how every stored
     * order refers to its account, so it identifies the row rather than
     * being editable — renaming it would orphan the orders that carry it.
     */
    public function saveAccount(Request $request, string $key): JsonResponse
    {
        $this->authorize('update', Requisition::class);

        $validated = $request->validate([
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'number' => 'required|string|max:64',
            'purpose' => 'required|string|max:191',
            'kind' => 'required|string|in:purchase,lease',
            'scope' => 'required|string|in:admin,curriculum',
            'payee' => 'required|string|in:ecu,csi',
            'schedule_type' => 'nullable|string|in:return,own',
            'sort' => 'nullable|integer|min:0',
            'active' => 'nullable|boolean',
        ]);

        $account = SupplierAccount::updateOrCreate(['key' => $key], $validated);

        SupplierAccounts::flush();

        return response()->json(Helper::formatStandardApiResponse('success', [
            'key' => $account->key,
            'number' => $account->number,
            'purpose' => $account->purpose,
        ], trans('admin/store/general.account_saved', ['account' => $account->number])));
    }

    /** Planning cadence and independently confirmed ordering windows. */
    public function cadence(): JsonResponse
    {
        $this->authorize('view', Requisition::class);

        return response()->json([
            'master_contract' => ProcurementSetting::get('lease_master_contract', CsiSchedule::MASTER_CONTRACT),
            'anchor_number' => (int) ProcurementSetting::get('lease_anchor_number', (string) CsiSchedule::ANCHOR_NUMBER),
            'anchor_quarter_start' => ProcurementSetting::get('lease_anchor_quarter_start', CsiSchedule::ANCHOR_QUARTER_START),
            'open_pair' => CsiSchedule::openPair(),
            'projected_pair' => CsiSchedule::projectedPair(),
            'open_schedules' => CsiSchedule::orderingWindows(),
            'ordering_windows' => CsiSchedule::orderingWindows(false),
        ]);
    }

    public function saveCadence(Request $request): JsonResponse
    {
        $this->authorize('update', Requisition::class);

        $validated = $request->validate([
            'master_contract' => 'nullable|string|max:32',
            'anchor_number' => 'nullable|integer|min:1|max:9999',
            'anchor_quarter_start' => 'nullable|date',
            'open_schedules' => 'sometimes|array|max:32',
            'open_schedules.*' => 'array:schedule,type,scope,ordering_from,ordering_until,planned_commencement,bpo_reference,bpo_valid_until,confirmed_at,source_reference,closed_at',
            'open_schedules.*.schedule' => 'required|string|max:64|distinct',
            'open_schedules.*.type' => 'required|in:return,own',
            'open_schedules.*.scope' => 'nullable|in:admin,curriculum',
            'open_schedules.*.ordering_from' => 'nullable|date_format:Y-m-d',
            'open_schedules.*.ordering_until' => 'required|date_format:Y-m-d',
            'open_schedules.*.planned_commencement' => 'nullable|date_format:Y-m-d',
            'open_schedules.*.bpo_reference' => 'nullable|string|max:64',
            'open_schedules.*.bpo_valid_until' => 'nullable|date_format:Y-m-d',
            'open_schedules.*.confirmed_at' => 'required|date',
            'open_schedules.*.source_reference' => 'nullable|string|max:1000',
            'open_schedules.*.closed_at' => 'nullable|date',

        ]);

        foreach ($validated['open_schedules'] ?? [] as $window) {
            if (! empty($window['ordering_from']) && $window['ordering_from'] > $window['ordering_until']) {
                throw ValidationException::withMessages(['open_schedules' => 'Ordering start must not be after its end.']);
            }
        }

        if (array_key_exists('open_schedules', $validated)) {
            ProcurementSetting::put('lease_open_schedules', json_encode($validated['open_schedules'], JSON_THROW_ON_ERROR));
        }

        foreach ([
            'lease_master_contract' => $validated['master_contract'] ?? null,
            'lease_anchor_number' => isset($validated['anchor_number']) ? (string) $validated['anchor_number'] : null,
            'lease_anchor_quarter_start' => isset($validated['anchor_quarter_start'])
                ? date('Y-m-d', strtotime($validated['anchor_quarter_start']))
                : null,
        ] as $key => $value) {
            if ($value !== null) {
                ProcurementSetting::put($key, $value);
            }
        }

        return response()->json(Helper::formatStandardApiResponse('success', [
            'open_pair' => CsiSchedule::openPair(),
            'projected_pair' => CsiSchedule::projectedPair(),
            'open_schedules' => CsiSchedule::orderingWindows(),
            'ordering_windows' => CsiSchedule::orderingWindows(false),
        ], trans('admin/store/general.cadence_saved')));
    }
}
