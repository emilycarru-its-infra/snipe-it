<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\StaffBlackout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manual CRUD for staff availability blackouts (vacation / OOO windows) that
 * the deployments Gantt overlays for capacity planning. UI-created rows are
 * source='manual'; the M365 calendar sync (deployment-staff-sync function)
 * writes source='graph' rows through the API upsert endpoint instead.
 * Authorization reuses the Order policy, mirroring the deployment board.
 */
class StaffBlackoutsController extends Controller
{
    /** List all blackouts, newest first, with the staff member's name. */
    public function index()
    {
        $this->authorize('view', Order::class);

        return view('deployment-blackouts.index', [
            'blackouts' => StaffBlackout::with('user')->orderByDesc('start_date')->orderByDesc('id')->get(),
        ]);
    }

    public function create()
    {
        $this->authorize('create', Order::class);

        return view('deployment-blackouts.form', [
            'blackout' => new StaffBlackout(['source' => 'manual']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $blackout = new StaffBlackout;
        $blackout->fill($this->input($request));
        $blackout->source = 'manual';

        if (! $blackout->save()) {
            return redirect()->back()->withInput()->withErrors($blackout->getErrors());
        }

        return redirect()->route('deployments.blackouts.index')
            ->with('success', trans('admin/deployments/general.blackout_saved'));
    }

    public function edit(StaffBlackout $blackout)
    {
        $this->authorize('update', Order::class);

        if ($blackout->source !== 'manual') {
            return redirect()->route('deployments.blackouts.index')
                ->with('error', trans('admin/deployments/general.blackout_synced_readonly'));
        }

        return view('deployment-blackouts.form', [
            'blackout' => $blackout,
        ]);
    }

    public function update(Request $request, StaffBlackout $blackout): RedirectResponse
    {
        $this->authorize('update', Order::class);

        if ($blackout->source !== 'manual') {
            return redirect()->route('deployments.blackouts.index')
                ->with('error', trans('admin/deployments/general.blackout_synced_readonly'));
        }

        $blackout->fill($this->input($request));

        if (! $blackout->save()) {
            return redirect()->back()->withInput()->withErrors($blackout->getErrors());
        }

        return redirect()->route('deployments.blackouts.index')
            ->with('success', trans('admin/deployments/general.blackout_saved'));
    }

    public function destroy(StaffBlackout $blackout): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        if ($blackout->source !== 'manual') {
            return redirect()->route('deployments.blackouts.index')
                ->with('error', trans('admin/deployments/general.blackout_synced_readonly'));
        }

        $blackout->delete();

        return redirect()->route('deployments.blackouts.index')
            ->with('success', trans('admin/deployments/general.blackout_deleted'));
    }

    /** Pull the editable fields off the request (validation runs on save via the model). */
    private function input(Request $request): array
    {
        return [
            'user_id' => $request->input('user_id'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'reason' => $request->input('reason'),
        ];
    }
}
