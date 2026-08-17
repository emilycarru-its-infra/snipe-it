<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetBuyout;
use App\Services\Leasing\BuyoutTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The stretch of a buyout that used to be a mail thread: recording what the
 * lessor quoted, what the buyer owes against what ECU absorbs, the decision,
 * the invoice and the payment.
 *
 * Authorized on the asset's `requestBuyout` policy rather than `update`, so
 * the HR and Finance staff who run these — the people who open almost every
 * one of them — can move a buyout along without holding assets.edit.
 */
class AssetBuyoutsController extends Controller
{
    /**
     * The per-device buyout page — a link you can hand to the lessor
     * thread. Resolved by asset tag (serial as fallback); shows the
     * operative buyout (open first, else the newest) with the same full
     * editor the in-flight table expands to. A device with no buyout yet
     * gets the page too, with a button to open one.
     */
    public function show(string $assetTag)
    {
        $this->authorize('deployments.view');

        $asset = Asset::where('asset_tag', $assetTag)->first()
            ?: Asset::where('serial', $assetTag)->firstOrFail();

        $buyouts = AssetBuyout::with(['asset.model', 'lessor', 'buyer', 'quotes'])
            ->where('asset_id', $asset->id)
            ->orderByRaw('CASE WHEN status IN ("completed", "declined") THEN 1 ELSE 0 END')
            ->orderByDesc('requested_at')
            ->get();

        $lane = new \App\Services\Deployments\DecommissionLane;

        return view('buyouts.show', [
            'asset' => $asset->loadMissing(['model', 'lessor', 'assignedTo']),
            'row' => $buyouts->first() ? $lane->buyoutRow($buyouts->first()) : null,
            'others' => $buyouts->slice(1)->map(fn ($b) => $lane->buyoutRow($b))->values(),
        ]);
    }

    /**
     * Open a buyout for a device straight from its page — the explicit
     * button, never a side effect of visiting the URL.
     */
    public function open(Request $request): RedirectResponse
    {
        $this->authorize('requestBuyout', Asset::class);

        $request->validate(['asset_id' => 'required|integer|exists:assets,id']);
        $asset = Asset::findOrFail((int) $request->input('asset_id'));

        if (AssetBuyout::where('asset_id', $asset->id)->open()->exists()) {
            return back()->with('error', trans('admin/deployments/general.buyout_already_open'));
        }

        AssetBuyout::create([
            'asset_id' => $asset->id,
            'lessor_id' => $asset->lessor_id ?? null,
            'buyer_id' => $asset->assigned_type === \App\Models\User::class ? $asset->assigned_to : null,
            'status' => 'requested',
            'requested_at' => now(),
            'requested_by' => auth()->id(),
        ]);

        return back()->with('success', trans('admin/deployments/general.buyout_opened'));
    }

    /**
     * Every column the record carries, as validation rules. The web form and
     * the API both write through this: a buyout is assembled out of facts
     * that arrive by mail, out of order, and get corrected weeks later, so
     * there is no field here that is only writable at one stage.
     *
     * `asset_id` is nullable on purpose — a request can be opened against a
     * lease line before anybody has worked out which unit it is.
     */
    public const EDITABLE_RULES = [
        'asset_id' => 'nullable|integer|exists:assets,id',
        'lessor_id' => 'nullable|integer|exists:suppliers,id',
        'buyer_id' => 'nullable|integer|exists:users,id',
        'status' => 'nullable|in:requested,quoted,approved,invoiced,paid,completed,declined',
        'requested_at' => 'nullable|date',
        'quote_amount' => 'nullable|numeric|min:0',
        'remaining_rent' => 'nullable|numeric|min:0',
        'quoted_at' => 'nullable|date',
        'buyer_amount' => 'nullable|numeric|min:0',
        'ecu_amount' => 'nullable|numeric|min:0',
        'invoice_number' => 'nullable|string|max:64',
        'invoice_date' => 'nullable|date',
        'invoice_due_date' => 'nullable|date',
        'paid_at' => 'nullable|date',
        'payment_method' => 'nullable|in:payroll_deduction,invoice,other',
        'payment_reference' => 'nullable|string|max:255',
        'decline_reason' => 'nullable|string|max:255',
        'notes' => 'nullable|string|max:2000',
    ];

    /** Statuses reachable from the board, and what each one needs typed in. */
    private const TRANSITION_RULES = [
        'approved' => [],
        'declined' => ['decline_reason' => 'nullable|string|max:255'],
        'invoiced' => [
            'invoice_number' => 'nullable|string|max:64',
            'invoice_date' => 'nullable|date',
            'invoice_due_date' => 'nullable|date',
        ],
        'paid' => [
            'paid_at' => 'nullable|date',
            'payment_method' => 'nullable|in:payroll_deduction,invoice,other',
            'payment_reference' => 'nullable|string|max:255',
        ],
        'completed' => [],
    ];

    /** Record a lessor quote. Supersedes the live one, keeps the old. */
    public function quote(Request $request, AssetBuyout $buyout): RedirectResponse
    {
        $this->authorizeBuyout($buyout);

        $data = $request->validate([
            'quote_amount' => 'nullable|numeric|min:0',
            'remaining_rent' => 'nullable|numeric|min:0',
            'quoted_at' => 'nullable|date',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        app(BuyoutTracker::class)->recordQuote($buyout, [
            'quote_amount' => $data['quote_amount'] ?? null,
            'remaining_rent' => $data['remaining_rent'] ?? null,
            'quoted_at' => $data['quoted_at'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], auth()->user());

        return back()->with('success', trans('admin/deployments/general.buyout_quote_recorded'));
    }

    /**
     * The whole record, corrected rather than advanced. Editable at any stage,
     * including after completion, because a payroll correction lands weeks
     * later and a mis-linked asset is found later still.
     */
    public function update(Request $request, AssetBuyout $buyout): RedirectResponse
    {
        $this->authorizeBuyout($buyout);

        $data = $request->validate(self::EDITABLE_RULES);

        static::applyEdit($buyout, $data, $request->keys(), auth()->user());

        return back()->with('success', trans('admin/deployments/general.buyout_updated'));
    }

    /** Advance to a named status, with only the fields that status owns. */
    public function transition(Request $request, AssetBuyout $buyout): RedirectResponse
    {
        $this->authorizeBuyout($buyout);

        $status = (string) $request->input('status');

        if (! array_key_exists($status, self::TRANSITION_RULES)) {
            return back()->with('error', trans('admin/deployments/general.buyout_bad_status'));
        }

        $data = $request->validate(self::TRANSITION_RULES[$status]);

        app(BuyoutTracker::class)->transition($buyout, $status, $data, auth()->user());

        // Completing changes the device, not just the record, so say so.
        $message = $status === 'completed'
            ? trans('admin/deployments/general.buyout_completed_note', [
                'status' => app(BuyoutTracker::class)->completedStatus()?->name
                    ?: config('leasing.buyout_completed_status'),
            ])
            : trans('admin/deployments/general.buyout_updated');

        return back()->with('success', $message);
    }

    /**
     * Write a validated edit onto the record. Shared with the API so both
     * doors enforce the same two derivations that a plain column write would
     * miss: the quote total is the price plus the remaining rent, and a
     * status typed in here still has to run its transition, so the stage
     * stamps its timestamp and completing still releases the device.
     *
     * `$submitted` is the list of keys the caller actually sent — a PATCH
     * that omits a field must leave it alone rather than null it.
     */
    public static function applyEdit(AssetBuyout $buyout, array $data, array $submitted, $actor): void
    {
        $fields = array_intersect_key($data, array_flip($submitted));

        $status = $fields['status'] ?? null;
        unset($fields['status']);

        if (array_key_exists('quote_amount', $fields) || array_key_exists('remaining_rent', $fields)) {
            $amount = array_key_exists('quote_amount', $fields) ? $fields['quote_amount'] : $buyout->quote_amount;
            $rent = array_key_exists('remaining_rent', $fields) ? $fields['remaining_rent'] : $buyout->remaining_rent;
            $fields['quote_total'] = ((float) $amount) + ((float) $rent);
        }

        if ($fields) {
            $buyout->forceFill($fields)->save();
        }

        if ($status !== null && $status !== $buyout->status) {
            // Hand the values just written back to the transition so its own
            // per-status fields are a no-op overwrite rather than a wipe.
            app(BuyoutTracker::class)->transition($buyout, $status, [
                'decline_reason' => $buyout->decline_reason,
                'invoice_number' => $buyout->invoice_number,
                'invoice_date' => $buyout->invoice_date,
                'invoice_due_date' => $buyout->invoice_due_date,
                'paid_at' => $buyout->paid_at,
                'payment_method' => $buyout->payment_method,
                'payment_reference' => $buyout->payment_reference,
            ], $actor);
        }
    }

    /**
     * A buyout with no asset linked yet is still a buyout somebody has to be
     * able to work on — the policy takes a nullable asset, so fall back to
     * the class rather than handing the gate a null it cannot resolve.
     */
    private function authorizeBuyout(AssetBuyout $buyout): void
    {
        $this->authorize('requestBuyout', $buyout->asset ?? Asset::class);
    }
}
