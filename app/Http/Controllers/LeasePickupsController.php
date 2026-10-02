<?php

namespace App\Http\Controllers;

use App\Mail\LeasePickupRequestMail;
use App\Models\Asset;
use App\Models\LeasePickup;
use App\Services\Leasing\PickupRequester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lease-return pickups on the decommissioning lane: bundle the devices
 * waiting to go back, ask the lessor to collect them, then record the load
 * number, the booked window and the day they left.
 */
class LeasePickupsController extends Controller
{
    /** Bundle the ticked devices and mail each lessor its pickup request. */
    public function store(Request $request, PickupRequester $requester): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'integer',
            'preferred_dates' => 'nullable|string|max:191',
            'pickup_notes' => 'nullable|string|max:2000',
            // The request tells the lessor the devices are wiped, released
            // from management and packed with their adapters; it only goes
            // once someone has said so.
            'confirmed_ready' => 'accepted',
        ]);

        $assets = Asset::query()->whereIn('id', $request->input('asset_ids'))->get();
        $result = $requester->request(
            $assets,
            $request->user(),
            $request->input('preferred_dates'),
            $request->input('pickup_notes')
        );

        $redirect = redirect()->back();
        if ($result['skipped']) {
            $redirect->with('warning', collect($result['skipped'])
                ->map(fn ($key, $tag) => $tag.': '.trans($key))
                ->implode(' '));
        }
        if ($result['pickups']->isEmpty()) {
            return $redirect->with('error', trans('admin/deployments/general.pickup_none_sent'));
        }

        return $redirect->with('success', trans('admin/deployments/general.pickup_requested', [
            'devices' => $result['pickups']->sum(fn (LeasePickup $pickup) => $pickup->assets->count()),
            'lessors' => $result['pickups']->map(fn (LeasePickup $pickup) => $pickup->lessor?->name)->filter()->implode(', '),
        ]));
    }

    /** Record the lessor's load number and booked window. */
    public function schedule(Request $request, LeasePickup $pickup, PickupRequester $requester): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $request->validate([
            'load_number' => 'nullable|string|max:64',
            'scheduled_date' => 'nullable|date',
            'scheduled_window' => 'nullable|string|max:64',
        ]);

        $requester->schedule(
            $pickup,
            $request->input('load_number'),
            $request->input('scheduled_date'),
            $request->input('scheduled_window')
        );

        return redirect()->back()->with('success', trans('admin/deployments/general.pickup_updated'));
    }

    /** The truck took them: return every device still waiting on this pickup. */
    public function pickedUp(Request $request, LeasePickup $pickup, PickupRequester $requester): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $request->validate(['picked_up_at' => 'required|date']);
        abort_unless($pickup->isOpen(), 422);

        $requester->pickedUp($pickup, $request->date('picked_up_at')->toDateString(), $request->user());

        return redirect()->back()->with('success', trans('admin/deployments/general.pickup_marked', [
            'count' => $pickup->assets()->count(),
        ]));
    }

    public function cancel(LeasePickup $pickup, PickupRequester $requester): RedirectResponse
    {
        $this->authorize('deployments.edit');
        abort_unless($pickup->isOpen(), 422);

        $requester->cancel($pickup);

        return redirect()->back()->with('success', trans('admin/deployments/general.pickup_cancelled'));
    }

    /** The device list of one pickup, as sent to the lessor. */
    public function csv(LeasePickup $pickup): Response
    {
        $this->authorize('deployments.view');

        return response((new LeasePickupRequestMail($pickup))->csv(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="lease-return-pickup-'.$pickup->id.'.csv"',
        ]);
    }
}
