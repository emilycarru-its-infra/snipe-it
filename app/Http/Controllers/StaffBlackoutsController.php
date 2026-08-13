<?php

namespace App\Http\Controllers;

use App\Models\StaffBlackout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manual CRUD for staff availability blackouts (vacation / OOO windows) that
 * the deployments Gantt overlays for capacity planning. UI-created rows are
 * source='manual'; the M365 calendar sync (deployment-staff-sync function)
 * writes source='graph' rows through the API upsert endpoint instead.
 * Gated by the deployments module permission.
 */
class StaffBlackoutsController extends Controller
{
    /**
     * The standalone list page is gone — the table is configuration and
     * lives on the Waves page now. The route name survives so old links
     * land in the right place.
     */
    public function index(): RedirectResponse
    {
        $this->authorize('deployments.view');

        return redirect()->route('deployment-waves.index');
    }

    public function create()
    {
        $this->authorize('deployments.edit');

        return view('deployment-blackouts.form', [
            'blackout' => new StaffBlackout(['source' => 'manual']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $blackout = new StaffBlackout;
        $blackout->fill($this->input($request));
        $blackout->source = 'manual';

        if (! $blackout->save()) {
            return redirect()->back()->withInput()->withErrors($blackout->getErrors());
        }

        return redirect()->route('deployment-waves.index')
            ->with('success', trans('admin/deployments/general.blackout_saved'));
    }

    public function edit(StaffBlackout $blackout)
    {
        $this->authorize('deployments.edit');

        if ($blackout->source !== 'manual') {
            return redirect()->route('deployment-waves.index')
                ->with('error', trans('admin/deployments/general.blackout_synced_readonly'));
        }

        return view('deployment-blackouts.form', [
            'blackout' => $blackout,
        ]);
    }

    public function update(Request $request, StaffBlackout $blackout): RedirectResponse
    {
        $this->authorize('deployments.edit');

        if ($blackout->source !== 'manual') {
            return redirect()->route('deployment-waves.index')
                ->with('error', trans('admin/deployments/general.blackout_synced_readonly'));
        }

        $blackout->fill($this->input($request));

        if (! $blackout->save()) {
            return redirect()->back()->withInput()->withErrors($blackout->getErrors());
        }

        return redirect()->route('deployment-waves.index')
            ->with('success', trans('admin/deployments/general.blackout_saved'));
    }

    public function destroy(StaffBlackout $blackout): RedirectResponse
    {
        $this->authorize('deployments.edit');

        if ($blackout->source !== 'manual') {
            return redirect()->route('deployment-waves.index')
                ->with('error', trans('admin/deployments/general.blackout_synced_readonly'));
        }

        $blackout->delete();

        return redirect()->route('deployment-waves.index')
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
