<?php

namespace App\Forms\FacultyProgram;

use App\Forms\FormDefinition;
use App\Models\Asset;
use App\Models\User;
use App\Models\UserAgreement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Faculty Laptop Program intake — first form on the /forms platform.
 * Captures payment intent, prior-laptop buyout decision, and the
 * terms acceptance, then sends faculty on to choose a machine in the
 * store. (Until the 2026-07-29 process change that last step was CDW's
 * own eStore; it is ours now.)
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
        // Every laptop in their hands, not just the best guess: someone with
        // two machines picks which one this renewal is about, and that pick
        // is what the order's handover page shows as "the old one".
        $laptops = Asset::laptopsOf($user->id);

        $existingPickup = $this->existingPickup($user);

        $priorAsset = $laptops->first();

        return view('forms.faculty-program.show', [
            'user' => $user,
            'laptops' => $laptops,
            'priorAsset' => $priorAsset,
            // "Based on your last model, the new comparable is …" — the
            // store catalog item mapped on the old laptop's model, priced
            // live from the catalog.
            'comparable' => $priorAsset?->model?->refreshCatalogItem,
            'buyoutCosts' => $laptops->mapWithKeys(fn (Asset $laptop) => [
                $laptop->id => $this->buyoutCostFor($laptop),
            ]),
            'existingPickup' => $existingPickup,
            'existingPurchase' => $this->existingPurchase($user),
            // Editing stops once the paperwork leaves the quoted stage: a
            // sent or signed agreement is a document someone acted on, and
            // silently rewriting it would falsify what they signed.
            'editable' => $existingPickup === null || $existingPickup->lifecycle_stage === 'quoted',
        ]);
    }

    public function submit(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'acknowledge_top_up' => 'accepted',
            'payment_method' => 'required|string|in:'.implode(',', UserAgreement::PAYMENT_METHODS),
            'buyout_decision' => 'required|string|in:yes,no,no_prior_laptop',
            'returning_asset_id' => 'nullable|integer',
            'buyout_asset_tag' => 'nullable|string|max:191|required_if:buyout_decision,yes',
            'buyout_serial' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:65535',
            'accept_terms' => 'accepted',
        ]);

        $laptops = Asset::laptopsOf($user->id);

        // "I don't have a laptop" is not a claim someone holding one gets
        // to make — the UI doesn't offer it when a machine was found, and
        // a hand-crafted POST doesn't get to either.
        if ($validated['buyout_decision'] === 'no_prior_laptop' && $laptops->isNotEmpty()) {
            throw ValidationException::withMessages([
                'buyout_decision' => trans('admin/forms/faculty-program.buyout_have_laptop'),
            ]);
        }

        // The machine this submission is about. Their pick when they made
        // one and it is genuinely theirs; the resolver's best guess
        // otherwise. Stored on the agreement so every later surface — the
        // order's handover page, the -LE rename, the queue — talks about
        // the machine they pointed at, not a re-guess.
        $returning = null;
        if ($validated['buyout_decision'] !== 'no_prior_laptop') {
            $returning = $laptops->firstWhere('id', (int) ($validated['returning_asset_id'] ?? 0))
                ?? $laptops->first();
        }

        $now = now();

        // One application per cycle. A repeat submission edits the open
        // agreement in place — never a second record — and once the
        // paperwork moves past quoted (sent for signature or beyond),
        // answers freeze: rewriting a document someone signed would
        // falsify it, so those changes go through us instead.
        $existingPickup = $this->existingPickup($user);
        if ($existingPickup && $existingPickup->lifecycle_stage !== 'quoted') {
            return redirect()
                ->route('forms.show', ['slug' => $this->slug()])
                ->with('error', trans('admin/forms/faculty-program.locked_error'));
        }

        $pickupValues = [
            'agreement_type' => 'pickup',
            'user_id' => $user->id,
            'asset_id' => $returning?->id,
            'lifecycle_stage' => 'quoted',
            'payment_method' => $validated['payment_method'],
            'terms_accepted_at' => $now,
            'notes' => $validated['notes'] ?? null,
        ];

        if ($existingPickup) {
            $existingPickup->update($pickupValues);
            $pickup = $existingPickup;
        } else {
            $pickup = UserAgreement::create($pickupValues);
        }

        $existingPurchase = $this->existingPurchase($user);

        $buyout = null;
        if ($validated['buyout_decision'] === 'yes') {
            $buyoutValues = [
                'agreement_type' => 'purchase',
                'user_id' => $user->id,
                'asset_id' => $returning?->id,
                'lifecycle_stage' => 'quoted',
                'payment_method' => $validated['payment_method'],
                'terms_accepted_at' => $now,
                'old_asset_tag' => $validated['buyout_asset_tag'] ?? $returning?->asset_tag,
                'old_serial' => $validated['buyout_serial'] ?? $returning?->serial,
                'notes' => $validated['notes'] ?? null,
            ];

            if ($existingPurchase && $existingPurchase->lifecycle_stage === 'quoted') {
                $existingPurchase->update($buyoutValues);
                $buyout = $existingPurchase;
            } else {
                $buyout = UserAgreement::create($buyoutValues);
            }
        } elseif ($existingPurchase && $existingPurchase->lifecycle_stage === 'quoted') {
            // They changed their mind about keeping the old machine — the
            // quoted buyout is off, not orphaned.
            $existingPurchase->update(['lifecycle_stage' => 'cancelled']);
        }

        // Changed answers change the paperwork: any pre-rendered unsigned
        // PDF is re-rendered now so the next surface that serves it (the
        // signature email, the profile download) reflects what they just
        // said — a payment-method flip alters the repayment section.
        foreach ([$pickup, $buyout] as $agreement) {
            if ($agreement && $agreement->pdf_path) {
                $agreement->storeUnsignedPdf();
            }
        }

        return redirect()
            ->route('forms.success', ['slug' => $this->slug()])
            ->with('pickup_id', $pickup->id)
            ->with('buyout_id', $buyout?->id)
            ->with('updated', (bool) $existingPickup);
    }

    public function success(User $user): View
    {
        // Our own store unless something is configured, and the distinction
        // matters to the view: an internal destination should open in this tab
        // and carry no external-link affordance, because it is the next step of
        // the same journey rather than a handoff to someone else's site.
        $configured = config('forms.faculty_program.purchase_url');

        return view('forms.faculty-program.success', [
            'purchaseUrl' => $configured ?: route('store.index'),
            'purchaseIsExternal' => (bool) $configured,
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

    private function existingPurchase(User $user): ?UserAgreement
    {
        return UserAgreement::where('user_id', $user->id)
            ->where('agreement_type', 'purchase')
            ->whereIn('lifecycle_stage', ['quoted', 'agreement_sent', 'agreement_signed', 'deployed', 'in_repayment'])
            ->latest('created_at')
            ->first();
    }

    private function existingPickup(User $user): ?UserAgreement
    {
        return UserAgreement::where('user_id', $user->id)
            ->where('agreement_type', 'pickup')
            ->whereIn('lifecycle_stage', ['quoted', 'agreement_sent', 'agreement_signed', 'deployed', 'in_repayment'])
            ->latest('created_at')
            ->first();
    }

    /**
     * Prior-laptop buyout estimate from the native `buyout_cost` column
     * (mirrored from the "Buyout Cost" custom field). Returns null when
     * unset or non-numeric.
     */
    private function buyoutCostFor(?Asset $asset): ?float
    {
        if (! $asset) {
            return null;
        }
        $value = $asset->buyout_cost;

        return is_numeric($value) ? (float) $value : null;
    }
}
