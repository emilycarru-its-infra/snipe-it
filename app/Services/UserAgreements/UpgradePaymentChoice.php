<?php

namespace App\Services\UserAgreements;

use App\Enums\ActionType;
use App\Models\Actionlog;
use App\Models\CheckoutAcceptance;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\Settings\Preferences;
use Illuminate\Support\Facades\DB;

/** A recipient reviews new payment terms before submitting their acceptance. */
class UpgradePaymentChoice
{
    public function review(int $acceptanceId, User $recipient, string $method): UserAgreement
    {
        abort_unless(in_array($method, UserAgreement::PAYMENT_METHODS, true), 422);
        $agreement = DB::transaction(function () use ($acceptanceId, $recipient, $method) {
            $acceptance = CheckoutAcceptance::query()->lockForUpdate()->findOrFail($acceptanceId);
            abort_unless((int) $acceptance->assigned_to_id === (int) $recipient->id, 403);
            $agreement = UserAgreement::query()->where('checkout_acceptance_id', $acceptance->id)->lockForUpdate()->firstOrFail();
            abort_unless($acceptance->isPending() && ! $agreement->signed_at
                && $agreement->lifecycle_stage === 'agreement_sent' && $agreement->agreement_type === 'upgrade'
                && (int) $agreement->user_id === (int) $recipient->id, 422);
            $agreement->assertKnownPrice();
            $agreement->documentBody();
            abort_unless($agreement->asset && \App\Models\Company::isCurrentUserHasAccess($agreement->asset), 403);
            $count = $method === 'pay_in_full' ? 1 : max(1, (int) Preferences::get('agreements.loan_installments'));
            $agreement->forceFill(['payment_method' => $method, 'installment_count' => $count,
                'installment_amount' => round((float) $agreement->top_up_amount / $count, 2)])->saveQuietly();
            $acceptance->eula_text_override = $agreement->eulaBody();
            $acceptance->save();
            $log = new Actionlog;
            $log->item_type = UserAgreement::class;
            $log->item_id = $agreement->id;
            $log->target_type = User::class;
            $log->target_id = $recipient->id;
            $log->setAttribute('created_by', $recipient->id);
            $log->note = 'Recipient selected upgrade payment option: '.$method.'.';
            $log->logaction(ActionType::NoteAdded);

            return $agreement;
        });
        $agreement->storeUnsignedPdf();

        return $agreement;
    }
}
