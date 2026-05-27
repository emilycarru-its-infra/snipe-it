<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\UserAgreement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * User-facing intake form. Authenticated users in the gate group
 * (config('user-form.group')) can submit a commitment that creates
 * agreement records at lifecycle_stage='quoted'. Hardware selection
 * happens externally — the form is pure commitment capture, then
 * redirects to the configured external purchase URL.
 */
class UserFormController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();
        abort_unless(self::isEligible($user), 403, trans('admin/user-form/general.not_eligible'));

        return view('user-form.show', [
            'user'           => $user,
            'priorAsset'     => $this->findPriorAsset($user),
            'existingPickup' => UserAgreement::where('user_id', $user->id)
                ->where('agreement_type', 'pickup')
                ->whereIn('lifecycle_stage', ['quoted', 'agreement_sent', 'agreement_signed', 'deployed', 'in_repayment'])
                ->latest('created_at')
                ->first(),
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $user = Auth::user();
        abort_unless(self::isEligible($user), 403, trans('admin/user-form/general.not_eligible'));

        $validated = $request->validate([
            'payment_method'    => 'required|string|in:'.implode(',', UserAgreement::PAYMENT_METHODS),
            'buyout_decision'   => 'required|string|in:yes,no,no_prior_laptop',
            'buyout_asset_tag'  => 'nullable|string|max:191|required_if:buyout_decision,yes',
            'buyout_serial'     => 'nullable|string|max:191',
            'notes'             => 'nullable|string|max:65535',
            'accept_terms'      => 'accepted',
        ]);

        $now = now();

        $pickup = UserAgreement::create([
            'agreement_type'    => 'pickup',
            'user_id'           => $user->id,
            'lifecycle_stage'   => 'quoted',
            'payment_method'    => $validated['payment_method'],
            'terms_accepted_at' => $now,
            'notes'             => $validated['notes'] ?? null,
        ]);

        $buyout = null;
        if ($validated['buyout_decision'] === 'yes') {
            $buyout = UserAgreement::create([
                'agreement_type'    => 'lease_end_purchase',
                'user_id'           => $user->id,
                'lifecycle_stage'   => 'quoted',
                'payment_method'    => $validated['payment_method'],
                'terms_accepted_at' => $now,
                'old_asset_tag'     => $validated['buyout_asset_tag'] ?? null,
                'old_serial'        => $validated['buyout_serial'] ?? null,
                'notes'             => $validated['notes'] ?? null,
            ]);
        }

        return redirect()
            ->route('user-form.success')
            ->with('pickup_id', $pickup->id)
            ->with('buyout_id', $buyout?->id);
    }

    public function success(): View|RedirectResponse
    {
        $user = Auth::user();
        abort_unless(self::isEligible($user), 403, trans('admin/user-form/general.not_eligible'));

        return view('user-form.success', [
            'externalPurchaseUrl' => config('user-form.external_purchase_url'),
        ]);
    }

    /**
     * Whether the user can use the intake form. Memoized per request so
     * the same lookup invoked from a view composer + the controller's
     * abort_unless guards doesn't fire multiple DB queries.
     */
    public static function isEligible(?User $user): bool
    {
        static $cache = [];

        $group = config('user-form.group');
        if (! $user || ! $group) {
            return false;
        }

        $key = $user->id.'|'.$group;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        return $cache[$key] = $user->groups()->where('name', $group)->exists();
    }

    /**
     * Most recent asset assigned to the user — used to pre-fill the
     * buyout decision. Falls back to null if the user has nothing
     * currently checked out.
     */
    private function findPriorAsset(User $user): ?Asset
    {
        return Asset::where('assigned_to', $user->id)
            ->where('assigned_type', User::class)
            ->orderByDesc('last_checkout')
            ->orderByDesc('purchase_date')
            ->first();
    }
}
