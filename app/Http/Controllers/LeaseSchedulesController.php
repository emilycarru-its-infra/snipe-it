<?php

namespace App\Http\Controllers;

use App\Models\LeaseSchedule;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CRUD plus mark-signed action for lease schedules. The read view lives
 * at the Schedule Signing Queue report (/reports/procurement/
 * schedule-signing); this controller handles entry, edits, and the
 * lifecycle bumps.
 *
 * Authorization piggy-backs on the orders permission set — same pattern
 * as LeaseDecisions and FacultyAgreements.
 */
class LeaseSchedulesController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('reports.procurement.schedule-signing');
    }

    public function create(Request $request)
    {
        $this->authorize('create', Order::class);

        return view('lease-schedules/create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $schedule = new LeaseSchedule;
        $schedule->fill($request->all());
        $schedule->lifecycle_stage = $request->input('lifecycle_stage', 'draft');
        $schedule->vendor_on_hold = $request->boolean('vendor_on_hold');
        $schedule->created_by = auth()->id();

        if (! $schedule->save()) {
            return redirect()->back()->withInput()->withErrors($schedule->getErrors());
        }

        return redirect()->route('lease-schedules.show', $schedule)
            ->with('success', trans('admin/lease-schedules/message.created'));
    }

    public function show(LeaseSchedule $leaseSchedule)
    {
        $this->authorize('view', Order::class);

        return view('lease-schedules/show', ['schedule' => $leaseSchedule]);
    }

    public function edit(LeaseSchedule $leaseSchedule)
    {
        $this->authorize('update', Order::class);

        return view('lease-schedules/edit', ['schedule' => $leaseSchedule]);
    }

    public function update(Request $request, LeaseSchedule $leaseSchedule): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $leaseSchedule->fill($request->all());
        $leaseSchedule->vendor_on_hold = $request->boolean('vendor_on_hold');

        if (! $leaseSchedule->save()) {
            return redirect()->back()->withInput()->withErrors($leaseSchedule->getErrors());
        }

        return redirect()->route('lease-schedules.show', $leaseSchedule)
            ->with('success', trans('admin/lease-schedules/message.updated'));
    }

    public function destroy(LeaseSchedule $leaseSchedule): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        $leaseSchedule->delete();

        return redirect()->route('reports.procurement.schedule-signing')
            ->with('success', trans('admin/lease-schedules/message.deleted'));
    }

    /**
     * Bump the schedule to `signed` and stamp the signer. Separate from
     * a generic edit so the audit trail is unambiguous about who signed
     * Section 7 — Mark / Viktor — versus who later flipped fields.
     */
    public function markSigned(LeaseSchedule $leaseSchedule): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $leaseSchedule->lifecycle_stage = 'signed';
        $leaseSchedule->signed_at = now();
        $leaseSchedule->signed_by = auth()->id();
        $leaseSchedule->save();

        return redirect()->route('lease-schedules.show', $leaseSchedule)
            ->with('success', trans('admin/lease-schedules/message.signed'));
    }
}
