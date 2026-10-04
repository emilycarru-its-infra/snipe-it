<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\LeasePickup;
use App\Services\Leasing\PickupRequester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lease-return pickups over the API — the same records and the same
 * PickupRequester the decommissioning lane uses, so a pickup can be raised,
 * answered and closed without the browser: request one for the devices
 * waiting to go back, record the lessor's load number and window, mark the
 * day the truck took them.
 */
class LeasePickupsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('deployments.view');

        $pickups = LeasePickup::with(['assets', 'lessor', 'requester'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->boolean('open'), fn ($q) => $q->open())
            ->when($request->filled('lessor_id'), fn ($q) => $q->where('lessor_id', $request->integer('lessor_id')))
            ->orderByRaw("CASE WHEN status IN ('requested','scheduled') THEN 0 ELSE 1 END")
            ->orderByDesc('requested_at')
            ->get();

        return response()->json(Helper::formatStandardApiResponse('success', [
            'total' => $pickups->count(),
            'rows' => $pickups->map(fn (LeasePickup $pickup) => $this->json($pickup))->values()->all(),
        ], null));
    }

    public function show(LeasePickup $pickup): JsonResponse
    {
        $this->authorize('deployments.view');

        return response()->json(Helper::formatStandardApiResponse('success', $this->json($pickup->load(['assets', 'lessor', 'requester'])), null));
    }

    /**
     * Request a pickup. Same rules as the lane: one pickup per lessor, each
     * lessor mailed its own devices, and nothing is sent until the caller
     * states the devices are wiped, released and packed (`confirmed_ready`).
     * Devices that could not be sent come back under `skipped`.
     */
    public function store(Request $request, PickupRequester $requester): JsonResponse
    {
        $this->authorize('deployments.edit');

        $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'integer',
            'preferred_dates' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:2000',
            'confirmed_ready' => 'accepted',
        ]);

        $result = $requester->request(
            Asset::query()->whereIn('id', $request->input('asset_ids'))->get(),
            $request->user(),
            $request->input('preferred_dates'),
            $request->input('notes')
        );

        $payload = [
            'pickups' => $result['pickups']->map(fn (LeasePickup $pickup) => $this->json($pickup))->values()->all(),
            'skipped' => collect($result['skipped'])->map(fn ($key) => trans($key))->all(),
        ];

        if ($result['pickups']->isEmpty()) {
            return response()->json(Helper::formatStandardApiResponse('error', $payload, trans('admin/deployments/general.pickup_none_sent')));
        }

        return response()->json(Helper::formatStandardApiResponse('success', $payload, trans('admin/deployments/general.pickup_requested', [
            'devices' => $result['pickups']->sum(fn (LeasePickup $pickup) => $pickup->assets->count()),
            'lessors' => $result['pickups']->map(fn (LeasePickup $pickup) => $pickup->lessor?->name)->filter()->implode(', '),
        ])));
    }

    /** Record the lessor's load number and booked window. */
    public function schedule(Request $request, LeasePickup $pickup, PickupRequester $requester): JsonResponse
    {
        $this->authorize('deployments.edit');

        $request->validate([
            'load_number' => 'nullable|string|max:64',
            'scheduled_date' => 'nullable|date',
            'scheduled_window' => 'nullable|string|max:64',
        ]);

        $requester->schedule($pickup, $request->input('load_number'), $request->input('scheduled_date'), $request->input('scheduled_window'));

        return response()->json(Helper::formatStandardApiResponse('success', $this->json($pickup->fresh(['assets', 'lessor', 'requester'])), trans('admin/deployments/general.pickup_updated')));
    }

    /** The truck took them: every device still waiting is returned. */
    public function pickedUp(Request $request, LeasePickup $pickup, PickupRequester $requester): JsonResponse
    {
        $this->authorize('deployments.edit');

        $request->validate(['picked_up_at' => 'required|date']);
        if (! $pickup->isOpen()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/deployments/general.pickup_not_open')));
        }

        $requester->pickedUp($pickup, $request->date('picked_up_at')->toDateString(), $request->user());

        return response()->json(Helper::formatStandardApiResponse('success', $this->json($pickup->fresh(['assets', 'lessor', 'requester'])), trans('admin/deployments/general.pickup_marked', [
            'count' => $pickup->assets()->count(),
        ])));
    }

    public function cancel(LeasePickup $pickup, PickupRequester $requester): JsonResponse
    {
        $this->authorize('deployments.edit');

        if (! $pickup->isOpen()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/deployments/general.pickup_not_open')));
        }
        $requester->cancel($pickup);

        return response()->json(Helper::formatStandardApiResponse('success', $this->json($pickup->fresh(['assets', 'lessor', 'requester'])), trans('admin/deployments/general.pickup_cancelled')));
    }

    private function json(LeasePickup $pickup): array
    {
        return [
            'id' => $pickup->id,
            'status' => $pickup->status,
            'lessor' => $pickup->lessor ? ['id' => $pickup->lessor->id, 'name' => $pickup->lessor->name] : null,
            'requested_by' => $pickup->requester ? ['id' => $pickup->requester->id, 'name' => $pickup->requester->full_name] : null,
            'requested_at' => $pickup->requested_at?->toDateTimeString(),
            'preferred_dates' => $pickup->preferred_dates,
            'notes' => $pickup->notes,
            'load_number' => $pickup->load_number,
            'scheduled_date' => $pickup->scheduled_date?->toDateString(),
            'scheduled_window' => $pickup->scheduled_window,
            'picked_up_at' => $pickup->picked_up_at?->toDateString(),
            'schedules' => $pickup->scheduleCounts(),
            'assets' => $pickup->assets->map(fn (Asset $asset) => [
                'id' => $asset->id,
                'asset_tag' => $asset->asset_tag,
                'serial' => $asset->serial,
                'lease_contract_id' => $asset->lease_contract_id,
                'lease_end_date' => $asset->lease_end_date,
            ])->values()->all(),
        ];
    }
}
