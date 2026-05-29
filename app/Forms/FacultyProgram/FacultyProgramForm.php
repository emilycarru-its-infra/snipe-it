<?php

namespace App\Forms\FacultyProgram;

use App\Forms\FormDefinition;
use App\Models\Asset;
use App\Models\CustomField;
use App\Models\User;
use App\Models\UserAgreement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Faculty Laptop Program intake — first form on the /forms platform.
 * Captures payment intent, prior-laptop buyout decision, and the
 * terms acceptance, then redirects faculty to the CDW eStore.
 *
 * Submissions land as UserAgreement rows at lifecycle_stage='quoted',
 * which downstream surfaces (the user-profile Agreements tab, the
 * pre-gen artisan command, the Send-for-Signature flow) consume.
 */
class FacultyProgramForm extends FormDefinition
{
    public function slug(): string
    {
        return 'faculty-program';
    }

    public function show(User $user): View
    {
        $priorAsset = $this->findPriorAsset($user);

        return view('forms.faculty-program.show', [
            'user'             => $user,
            'priorAsset'       => $priorAsset,
            'priorBuyoutCost'  => $this->buyoutCostFor($priorAsset),
            'existingPickup'   => $this->existingPickup($user),
        ]);
    }

    public function submit(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'acknowledge_top_up' => 'accepted',
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
            ->route('forms.success', ['slug' => $this->slug()])
            ->with('pickup_id', $pickup->id)
            ->with('buyout_id', $buyout?->id);
    }

    public function success(User $user): View
    {
        return view('forms.faculty-program.success', [
            'externalPurchaseUrl' => config('forms.faculty_program.external_purchase_url'),
        ]);
    }

    public function userSubmissions(User $user): Collection
    {
        return UserAgreement::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function submissionsIndexQuery(): Builder
    {
        return UserAgreement::query()
            ->with(['user', 'asset'])
            ->orderByDesc('created_at');
    }

    public function submissionsIndexView(Builder $query): View
    {
        return view('forms.faculty-program.submissions.index', [
            'agreements' => $query->paginate(50),
        ]);
    }

    public function submissionShow(Model $submission): View
    {
        /** @var UserAgreement $submission */
        return view('forms.faculty-program.submissions.show', [
            'agreement' => $submission->load(['user', 'asset']),
        ]);
    }

    public function findSubmission(int|string $id): ?Model
    {
        return UserAgreement::find($id);
    }

    public function submissionOwnerId(Model $submission): ?int
    {
        /** @var UserAgreement $submission */
        return $submission->user_id;
    }

    private function existingPickup(User $user): ?UserAgreement
    {
        return UserAgreement::where('user_id', $user->id)
            ->where('agreement_type', 'pickup')
            ->whereIn('lifecycle_stage', ['quoted', 'agreement_sent', 'agreement_signed', 'deployed', 'in_repayment'])
            ->latest('created_at')
            ->first();
    }

    private function findPriorAsset(User $user): ?Asset
    {
        return Asset::where('assigned_to', $user->id)
            ->where('assigned_type', User::class)
            ->orderByDesc('last_checkout')
            ->orderByDesc('purchase_date')
            ->first();
    }

    /**
     * Snipe-IT custom fields land as columns named `_snipeit_<slug>_<id>`,
     * where <id> is the field's auto-increment primary key and so
     * differs between environments. Look the field up by display name
     * ("Buyout Cost") instead — that's stable across envs. Returns null
     * when the field isn't installed or the asset has no value.
     */
    private function buyoutCostFor(?Asset $asset): ?float
    {
        if (! $asset) {
            return null;
        }
        $field = CustomField::where('name', 'Buyout Cost')->first();
        if (! $field) {
            return null;
        }
        $value = $asset->{$field->db_column_name()};
        return is_numeric($value) ? (float) $value : null;
    }
}
