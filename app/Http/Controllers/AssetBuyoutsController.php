<?php

namespace App\Http\Controllers;

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
        $this->authorize('requestBuyout', $buyout->asset);

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
     * The split, the buyer, and free-text notes — the fields that get corrected
     * rather than advanced. Editable at any stage, including after completion,
     * because a payroll correction lands weeks later.
     */
    public function update(Request $request, AssetBuyout $buyout): RedirectResponse
    {
        $this->authorize('requestBuyout', $buyout->asset);

        $data = $request->validate([
            'buyer_id' => 'nullable|integer|exists:users,id',
            'buyer_amount' => 'nullable|numeric|min:0',
            'ecu_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);

        $buyout->forceFill($data)->save();

        return back()->with('success', trans('admin/deployments/general.buyout_updated'));
    }

    /** Advance to a named status, with only the fields that status owns. */
    public function transition(Request $request, AssetBuyout $buyout): RedirectResponse
    {
        $this->authorize('requestBuyout', $buyout->asset);

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
}
