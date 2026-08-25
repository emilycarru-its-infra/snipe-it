<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\CdwAccount;
use App\Models\CsiSchedule;
use App\Models\ProcurementSetting;
use App\Models\Requisition;
use App\Services\CdwAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The vendor accounts and the lease-schedule cadence, editable.
 *
 * Both used to be PHP constants: changing an account number, or moving the
 * anchor when a new schedule pair opened, meant a pull request and a
 * deploy for a fact that lives on the vendor's timetable. A new pair opens
 * every three months, so the constant was guaranteed to go stale four times
 * a year.
 */
class ProcurementConfigController extends Controller
{
    public function accounts(): JsonResponse
    {
        $this->authorize('view', Requisition::class);

        return response()->json([
            'total' => CdwAccount::count(),
            'rows' => CdwAccount::orderBy('sort')->orderBy('key')->get()->map(fn (CdwAccount $account) => [
                'id' => $account->id,
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
            'number' => 'required|string|max:64',
            'purpose' => 'required|string|max:191',
            'kind' => 'required|string|in:purchase,lease',
            'scope' => 'required|string|in:admin,curriculum',
            'payee' => 'required|string|in:ecu,csi',
            'schedule_type' => 'nullable|string|in:return,own',
            'sort' => 'nullable|integer|min:0',
            'active' => 'nullable|boolean',
        ]);

        $account = CdwAccount::updateOrCreate(['key' => $key], $validated);

        CdwAccounts::flush();

        return response()->json(Helper::formatStandardApiResponse('success', [
            'key' => $account->key,
            'number' => $account->number,
            'purpose' => $account->purpose,
        ], trans('admin/store/general.account_saved', ['account' => $account->number])));
    }

    /**
     * The cadence: which schedule pair is open for ordering, and the master
     * contract they hang off. Reported with the pair it currently resolves
     * to, because that is the number anyone editing this is trying to get
     * right.
     */
    public function cadence(): JsonResponse
    {
        $this->authorize('view', Requisition::class);

        return response()->json([
            'master_contract' => ProcurementSetting::get('lease_master_contract', CsiSchedule::MASTER_CONTRACT),
            'anchor_number' => (int) ProcurementSetting::get('lease_anchor_number', (string) CsiSchedule::ANCHOR_NUMBER),
            'anchor_quarter_start' => ProcurementSetting::get('lease_anchor_quarter_start', CsiSchedule::ANCHOR_QUARTER_START),
            'open_pair' => CsiSchedule::openPair(),
        ]);
    }

    public function saveCadence(Request $request): JsonResponse
    {
        $this->authorize('update', Requisition::class);

        $validated = $request->validate([
            'master_contract' => 'nullable|string|max:32',
            'anchor_number' => 'nullable|integer|min:1|max:9999',
            'anchor_quarter_start' => 'nullable|date',
        ]);

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
        ], trans('admin/store/general.cadence_saved')));
    }
}
