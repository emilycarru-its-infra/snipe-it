<?php

namespace App\Http\Controllers;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\CustomField;
use App\Models\LeaseSchedule;
use App\Models\Order;
use App\Services\AnnexureParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD plus mark-signed action for lease schedules. The read view lives
 * at the Schedule Signing Queue report (/reports/procurement/
 * schedule-signing); this controller handles entry, edits, and the
 * lifecycle bumps.
 *
 * Authorization piggy-backs on the orders permission set — same pattern
 * as LeaseDecisions and UserAgreements.
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

    /**
     * Annexure A diff. Reads the most recently uploaded file attached
     * to the schedule, extracts serials, and compares them to assets
     * carrying the matching Lease Contract ID custom field. The result
     * is bucketed three ways so Sohee can see at a glance what the
     * lessor sent that we haven't received yet, and what we've received
     * that isn't on their Annexure.
     */
    public function annexureDiff(LeaseSchedule $leaseSchedule, AnnexureParser $parser): View|RedirectResponse
    {
        $this->authorize('view', Order::class);

        $upload = Actionlog::where('item_type', LeaseSchedule::class)
            ->where('item_id', $leaseSchedule->id)
            ->where('action_type', 'uploaded')
            ->whereNotNull('filename')
            ->orderByDesc('created_at')
            ->first();

        if (! $upload) {
            return redirect()->route('lease-schedules.show', $leaseSchedule)
                ->with('error', trans('admin/lease-schedules/message.annexure_no_upload'));
        }

        $path = 'private_uploads/lease-schedules/'.$upload->filename;
        $annexureSerials = collect($parser->serialsFromPdf($path));

        // Look up the Lease Contract ID column on assets once — the
        // schedule_ref string match mirrors the existing data flow.
        $contractIdColumn = CustomField::where('name', 'Lease Contract ID')->value('db_column');
        $snipeSerials = collect();

        if ($contractIdColumn) {
            $snipeSerials = Asset::query()
                ->where($contractIdColumn, $leaseSchedule->schedule_ref)
                ->whereNotNull('serial')
                ->where('serial', '!=', '')
                ->orderBy('serial')
                ->get(['id', 'asset_tag', 'serial', 'status_id']);
        }

        $snipeSerialIndex = $snipeSerials->keyBy(fn ($a) => strtoupper($a->serial));
        $annexureSerialUpper = $annexureSerials->map(fn ($s) => strtoupper($s));

        $matched = [];
        $missingInSnipe = [];
        foreach ($annexureSerialUpper as $serial) {
            if (isset($snipeSerialIndex[$serial])) {
                $matched[] = ['serial' => $serial, 'asset' => $snipeSerialIndex[$serial]];
            } else {
                $missingInSnipe[] = $serial;
            }
        }

        $annexureSet = $annexureSerialUpper->flip();
        $missingInAnnexure = $snipeSerials
            ->filter(fn ($a) => ! isset($annexureSet[strtoupper($a->serial)]))
            ->values();

        return view('lease-schedules/annexure-diff', [
            'schedule' => $leaseSchedule,
            'upload' => $upload,
            'annexureCount' => $annexureSerialUpper->count(),
            'snipeCount' => $snipeSerials->count(),
            'matched' => $matched,
            'missingInSnipe' => $missingInSnipe,
            'missingInAnnexure' => $missingInAnnexure,
            'parserUsable' => (bool) $contractIdColumn,
        ]);
    }
}
