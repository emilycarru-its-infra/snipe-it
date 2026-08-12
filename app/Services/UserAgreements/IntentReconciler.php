<?php

namespace App\Services\UserAgreements;

use App\Models\Asset;
use App\Models\User;
use App\Models\UserAgreement;
use Illuminate\Support\Collection;

/**
 * What faculty said they would do with the old laptop, against what happened.
 *
 * The form asks one question that costs real money: are you returning the machine
 * at lease end, or buying it at its residual value? A return that never arrives is
 * a device CSI invoices us for; a buyout that nobody charges is equipment given
 * away. Both were previously found by somebody reading the disposition grid beside
 * the submissions list in March and comparing names.
 *
 * So the answer is kept on the agreement, and this compares it to the device's
 * actual state. It reports; it never acts. A mismatch is usually a timing
 * difference — the machine is on its way back — and the one thing worse than not
 * noticing would be a job that "corrected" a faculty member's paperwork on a
 * Sunday because a laptop was late.
 */
class IntentReconciler
{
    /**
     * Every faculty answer with the state of the device it was about.
     *
     * @return Collection<int, array{
     *     agreement: UserAgreement, user: ?User, asset: ?Asset, intent: ?string,
     *     actual: string, matches: bool, note: string
     * }>
     */
    public function rows(?string $fiscalYear = null): Collection
    {
        $rows = collect();

        $agreements = UserAgreement::with('user', 'asset.assignedTo', 'asset.status')
            ->whereNotNull('stated_intent')
            ->when($fiscalYear, fn ($q) => $q->where('lease_contract', 'like', '%'.$fiscalYear.'%'))
            ->orderByDesc('terms_accepted_at')
            ->get();

        foreach ($agreements as $agreement) {
            $rows->push($this->describe($agreement));
        }

        return $rows->values();
    }

    /** Just the ones worth chasing. */
    public function mismatches(?string $fiscalYear = null): Collection
    {
        return $this->rows($fiscalYear)->reject(fn (array $row) => $row['matches'])->values();
    }

    /**
     * @return array{agreement: UserAgreement, user: ?User, asset: ?Asset, intent: ?string, actual: string, matches: bool, note: string}
     */
    public function describe(UserAgreement $agreement): array
    {
        $asset = $agreement->asset;
        $intent = $agreement->stated_intent;
        $actual = $this->actualState($agreement, $asset);

        // What "matching" means differs by answer, so it is spelled out rather
        // than derived from a pair of enums that happen to line up:
        //
        //   return  the device should have left them — checked in, disposed, or
        //           gone from our records entirely
        //   buyout  the device should still be theirs, and the purchase agreement
        //           should be signed or paid rather than sitting at quoted
        //   none    they said they had no prior laptop, so any device still
        //           checked out to them contradicts the answer
        $matches = match ($intent) {
            'return' => in_array($actual, ['returned', 'disposed', 'gone'], true),
            'buyout' => $actual === 'still_held' && in_array($agreement->lifecycle_stage, [
                'agreement_signed', 'deployed', 'in_repayment', 'paid_off', 'closed_buyout',
            ], true),
            'no_prior_laptop' => $actual === 'gone',
            default => true,
        };

        return [
            'agreement' => $agreement,
            'user' => $agreement->user,
            'asset' => $asset,
            'intent' => $intent,
            'actual' => $actual,
            'matches' => $matches,
            'note' => $matches ? '' : $this->mismatchNote($intent, $actual),
        ];
    }

    /**
     * The specific sentence for this pair when there is one, and a plain one when
     * there is not — a new combination should read as "these do not agree", never
     * as a missing translation key.
     */
    private function mismatchNote(?string $intent, string $actual): string
    {
        $key = 'admin/user-agreements/general.intent_mismatch_'.$intent.'_'.$actual;
        $specific = trans($key);

        return $specific === $key
            ? trans('admin/user-agreements/general.intent_mismatch_generic')
            : $specific;
    }

    /**
     * Where the old device actually is, in the four states that matter to the
     * question. Deliberately coarse: "returned" covers checked in and awaiting
     * collection alike, because both mean it is no longer the faculty member's.
     */
    private function actualState(UserAgreement $agreement, ?Asset $asset): string
    {
        if (! $asset) {
            return 'gone';
        }

        if ($asset->deleted_at !== null) {
            return 'disposed';
        }

        $status = $asset->status;

        if ($status && ($status->deployable == 0 && $status->archived == 1)) {
            return 'disposed';
        }

        // Still theirs only if it is still checked out to the same person the
        // agreement is about — a device reassigned to somebody else has left
        // them, whatever the paperwork says.
        $holder = $asset->holderUser();

        if ($holder && $agreement->user_id && (int) $holder->id === (int) $agreement->user_id) {
            return 'still_held';
        }

        return 'returned';
    }
}
