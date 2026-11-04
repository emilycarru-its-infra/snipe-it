<?php

namespace App\Services\Leasing;

use App\Mail\UserAgreementSignatureRequestMail;
use App\Models\AssetBuyout;
use App\Models\CheckoutAcceptance;
use App\Models\User;
use App\Models\UserAgreement;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Puts the buyer's purchase agreement in front of them to sign.
 *
 * A buyout is only finished on paper once the buyer has signed for the
 * device: the signed agreement is the authority payroll deducts against, and
 * it records that the device is bought as-is and leaves management. So when
 * the buyer agrees, this renders the purchase agreement from the buyout's own
 * figures, opens the signing request and mails the buyer the link. The
 * signature then carries the buyout on (see BuyoutTracker::signed).
 *
 * Idempotent. An agreement already linked is reused; an open purchase
 * agreement for the same buyer and device is adopted rather than duplicated;
 * a signed one is left alone. If the buyer's share changes before they sign,
 * the unsigned agreement is re-rendered at the new figure and sent again.
 */
class BuyoutAgreementIssuer
{
    /** Buyout payment route → the agreement's payment method. */
    private const PAYMENT_METHODS = [
        'payroll_deduction' => 'payroll_deduction',
        'invoice' => 'pay_in_full',
    ];

    /**
     * Issue (or refresh) the agreement. Returns a translation key saying why
     * nothing was sent, or null when the agreement is out for signature or
     * already signed. Never throws: a failure here must not undo the status
     * change that called it.
     */
    public function issue(AssetBuyout $buyout, ?User $actor = null): ?string
    {
        $buyout->loadMissing(['asset', 'buyer', 'agreement']);

        if (! $buyout->asset || ! $buyout->buyer) {
            return 'admin/deployments/general.buyout_agreement_no_buyer';
        }

        $amount = $buyout->buyer_amount ?? $buyout->quote_total;

        if ($amount === null) {
            return 'admin/deployments/general.buyout_payroll_no_quote';
        }

        try {
            $agreement = $buyout->agreement ?? $this->adopt($buyout) ?? new UserAgreement;

            if ($agreement->exists && $agreement->signed_at) {
                $this->link($buyout, $agreement);

                return null;
            }

            $changed = ! $agreement->exists
                || (float) $agreement->buyout_cost !== (float) $amount
                || $agreement->payment_method !== $this->paymentMethod($buyout);

            $agreement->forceFill([
                'agreement_type' => 'purchase',
                'user_id' => $buyout->buyer_id,
                'asset_id' => $buyout->asset_id,
                'buyout_cost' => $amount,
                'payment_method' => $this->paymentMethod($buyout),
                'old_asset_tag' => $buyout->asset->asset_tag,
                'old_serial' => $buyout->asset->serial,
                'lifecycle_stage' => $agreement->lifecycle_stage === 'agreement_sent' ? 'agreement_sent' : 'quoted',
            ]);
            // Quietly: the model's saved() hook would send for signature before
            // the PDF below exists, and the mail attaches that PDF.
            $agreement->saveQuietly();

            $this->link($buyout, $agreement);

            $agreement->storeUnsignedPdf();

            $acceptance = $agreement->checkout_acceptance_id
                ? CheckoutAcceptance::find($agreement->checkout_acceptance_id)
                : null;

            if (! $acceptance) {
                $agreement->sendForSignature();
            } elseif ($changed && $acceptance->isPending()) {
                // The figure moved after the request went out: the buyer must
                // sign what they will actually pay, so refresh and resend.
                $acceptance->eula_text_override = $agreement->eulaBody();
                $acceptance->save();
                $this->mail($agreement->fresh());
            }
        } catch (\Throwable $e) {
            Log::error('Buyout agreement failed for buyout '.$buyout->id.': '.$e->getMessage());

            return 'admin/deployments/general.buyout_agreement_failed';
        }

        return null;
    }

    /** Re-send the signing request for an agreement already out. */
    public function remind(AssetBuyout $buyout): ?string
    {
        $agreement = $buyout->agreement;

        if (! $agreement || $agreement->signed_at) {
            return $this->issue($buyout);
        }

        return $this->mail($agreement) ? null : 'admin/deployments/general.buyout_agreement_failed';
    }

    /**
     * An open purchase agreement already written for this buyer and device —
     * the faculty programme's reconciler creates them for lease-end buyouts —
     * is the same paper, so adopt it.
     */
    private function adopt(AssetBuyout $buyout): ?UserAgreement
    {
        return UserAgreement::where('agreement_type', 'purchase')
            ->where('user_id', $buyout->buyer_id)
            ->where('asset_id', $buyout->asset_id)
            ->whereIn('lifecycle_stage', UserAgreement::OPEN_LIFECYCLE_STAGES)
            ->latest('id')
            ->first();
    }

    private function link(AssetBuyout $buyout, UserAgreement $agreement): void
    {
        if ($buyout->user_agreement_id !== $agreement->id) {
            $buyout->forceFill(['user_agreement_id' => $agreement->id])->save();
            $buyout->setRelation('agreement', $agreement);
        }
    }

    private function paymentMethod(AssetBuyout $buyout): ?string
    {
        // An unset route is payroll, the usual one — same rule as the notifier.
        return self::PAYMENT_METHODS[$buyout->payment_method ?? 'payroll_deduction'] ?? null;
    }

    private function mail(UserAgreement $agreement): bool
    {
        if (! $agreement->user?->email) {
            return false;
        }

        try {
            Mail::to($agreement->user->email)->send(new UserAgreementSignatureRequestMail($agreement));
        } catch (\Throwable $e) {
            Log::error('Buyout agreement mail failed for agreement '.$agreement->id.': '.$e->getMessage());

            return false;
        }

        return true;
    }
}
