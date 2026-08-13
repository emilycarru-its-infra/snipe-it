<?php

namespace App\Forms\FacultyProgram;

use App\Forms\FormDefinition;
use App\Models\Asset;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\FacultyProgramNotifier;
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
            // Per-laptop comparables so the suggestion follows whichever
            // machine they say this renewal is about.
            'comparables' => $laptops->mapWithKeys(function (Asset $laptop) {
                $model = $laptop->model;
                $item = $model?->refreshCatalogItem;

                return [$laptop->id => $item ? [
                    'old' => $model->name,
                    'name' => $item->name,
                    'cost' => $item->effectiveCost(),
                ] : null];
            }),
            // "Based on your last model, the new comparable is …" — the
            // store catalog item mapped on the old laptop's model, priced
            // live from the catalog.
            'comparable' => $priorAsset?->model?->refreshCatalogItem,
            // A quote when the lessor has given one, an estimate otherwise.
            // Asking for a firm figure per machine would mean quoting every
            // laptop in the programme to serve the handful of people who
            // want to keep theirs, so the form estimates and the quote
            // follows the request rather than the other way round.
            'buyoutCosts' => $laptops->mapWithKeys(fn (Asset $laptop) => [
                $laptop->id => $this->buyoutCostFor($laptop) ?? $this->buyoutEstimateFor($laptop),
            ]),
            'buyoutIsQuoted' => $laptops->mapWithKeys(fn (Asset $laptop) => [
                $laptop->id => $this->buyoutCostFor($laptop) !== null,
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

        // The answer itself is kept, not just the agreements it produced. It is
        // what makes a later reconciliation possible: said return, still holding
        // it in March. See App\Services\UserAgreements\IntentReconciler.
        $intent = match ($validated['buyout_decision']) {
            'yes' => 'buyout',
            'no' => 'return',
            default => 'no_prior_laptop',
        };

        $pickupValues = [
            'agreement_type' => 'pickup',
            'stated_intent' => $intent,
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
                'stated_intent' => 'buyout',
                'user_id' => $user->id,
                'asset_id' => $returning?->id,
                'lifecycle_stage' => 'quoted',
                'payment_method' => $validated['payment_method'],
                'terms_accepted_at' => $now,
                'old_asset_tag' => $validated['buyout_asset_tag'] ?? $returning?->asset_tag,
                'old_serial' => $validated['buyout_serial'] ?? $returning?->serial,
                'notes' => $validated['notes'] ?? null,
            ];

            // Saying yes promotes the standing eligibility rather than
            // opening a second record beside it: the lease-end pipeline may
            // already have noted this machine as buyable, and two purchase
            // rows for one laptop is one of them being wrong.
            $slot = $existingPurchase ?? $this->eligiblePurchase($user);

            if ($slot) {
                $slot->update($buyoutValues);
                $buyout = $slot;
            } else {
                $buyout = UserAgreement::create($buyoutValues);
            }
        } elseif ($existingPurchase && $existingPurchase->lifecycle_stage === 'quoted') {
            // They changed their mind about keeping the old machine — the
            // quoted buyout is off, not orphaned. Only a quoted row is
            // cancelled here, because only a quoted row was ever a decision:
            // an eligible one is the pipeline noting the machine is buyable,
            // and answering "no" to a question nobody asked leaves nothing
            // to cancel.
            $existingPurchase->update(['lifecycle_stage' => 'cancelled']);
        }

        // The paperwork is rendered here, not waiting for somebody to press a
        // button per person. A buyout agreement that exists only as a database
        // row is not a document finance can act on, and the whole point of
        // asking the question in a form is that the answer produces the thing
        // it implies. Re-rendered on a resubmission too: a payment-method flip
        // alters the repayment section.
        foreach ([$pickup, $buyout] as $agreement) {
            if ($agreement) {
                $agreement->storeUnsignedPdf();
            }
        }

        // Somebody is told. Until this, a submission was recorded and
        // announced to nobody: the first the program heard of an
        // application was usually the applicant asking why nothing had
        // happened.
        FacultyProgramNotifier::submitted($pickup, $buyout, (bool) $existingPickup);

        // No lessor quote request goes out from here. Buyout values are
        // gathered in bulk by the device team, not one email per form
        // submission; the quote button on the asset page remains for the
        // cases that do warrant a per-device ask.

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

    /**
     * A buyout this person has actually decided on. Deliberately excludes
     * `eligible`, which the lease-end pipeline writes on its own: it means
     * the machine could be bought, not that anyone asked to buy it, and
     * treating it as an answer is what pre-selected "yes" on this form for
     * people who had never seen the question.
     */
    private function existingPurchase(User $user): ?UserAgreement
    {
        return UserAgreement::where('user_id', $user->id)
            ->where('agreement_type', 'purchase')
            ->whereIn('lifecycle_stage', ['quoted', 'agreement_sent', 'agreement_signed', 'deployed', 'in_repayment'])
            ->latest('created_at')
            ->first();
    }

    /** The standing "this machine is buyable" note, if the pipeline made one. */
    private function eligiblePurchase(User $user): ?UserAgreement
    {
        return UserAgreement::where('user_id', $user->id)
            ->where('agreement_type', 'purchase')
            ->where('lifecycle_stage', 'eligible')
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

    /**
     * What buying the old laptop is likely to cost, when nobody has asked
     * the lessor yet.
     *
     * A real quote only makes sense for the people who actually want one,
     * so the form estimates instead: roughly a year's rent, which on the
     * ECI20221001 schedule is a flat factor of the capital cost — 21.4%
     * before tax and a shade over 24% with it, holding to within a fifth
     * of a percentage point across all seven item types on the schedule,
     * from a Mac mini to a 16-inch Pro. So it is a lease factor, not a
     * curve, and multiplying the acquisition cost reproduces the lessor's
     * own numbers rather than approximating them.
     *
     * Tax-inclusive because that is what an invoice shows, and an estimate
     * that lands under the bill is the one that causes a problem.
     *
     * Returns null when there is no capital cost to work from — better a
     * missing estimate than a confident zero.
     */
    private function buyoutEstimateFor(?Asset $asset): ?float
    {
        if (! $asset || ! is_numeric($asset->purchase_cost) || (float) $asset->purchase_cost <= 0) {
            return null;
        }

        $factor = (float) config('forms.buyout_estimate.annual_rent_factor');

        return $factor > 0 ? round((float) $asset->purchase_cost * $factor, 2) : null;
    }
}
