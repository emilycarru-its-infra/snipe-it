<?php

namespace App\Services\Leasing;

use App\Enums\ActionType;
use App\Mail\AssetBuyoutPayrollMail;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetBuyout;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells finance how to collect the buyer's share, with the buyer's signed
 * purchase agreement attached — that signature is the authority to collect.
 * A payroll-deduction buyout goes to payroll; an invoice buyout (someone no
 * longer on payroll, such as after their final pay) goes to accounts
 * receivable to invoice the buyer.
 *
 * Fired when the buyer signs (BuyoutTracker::signed), so the step that used
 * to be a forwarded mail thread happens the moment the record says it should.
 * A buyout whose agreement is out but unsigned is refused: payroll would only
 * ask for the paper. A buyout with no agreement at all (one approved before
 * agreements were issued) still sends, without the attachment. Skipped when the
 * buyer settles some other way (`other`); an unset payment method is treated
 * as payroll, since that is the usual route, and is stamped as such once
 * payroll has been told.
 *
 * Addressing: To the route's list (Settings → Emails override, else the
 * `leasing.buyout_payroll_to` / `leasing.buyout_invoice_to` default), Cc the
 * configured team list plus the buyer — they asked what happens next, and
 * this is the answer.
 */
class BuyoutPayrollNotifier
{
    public const KEY = 'request.asset_buyout_payroll';

    public const INVOICE_KEY = 'request.asset_buyout_invoice';

    /**
     * Send the notice. Returns a translation key describing why it was not
     * sent, or null on success. Never throws: a mail failure must not undo
     * the approval that triggered it.
     */
    public function send(AssetBuyout $buyout, ?User $actor): ?string
    {
        if ($buyout->payment_method === 'other') {
            return 'admin/deployments/general.buyout_payroll_not_payroll';
        }

        if ($buyout->quote_total === null && $buyout->buyer_amount === null) {
            return 'admin/deployments/general.buyout_payroll_no_quote';
        }

        $buyout->loadMissing('agreement');
        if ($buyout->agreement && ! $buyout->agreement->signed_at) {
            return 'admin/deployments/general.buyout_payroll_unsigned';
        }

        // The signature is payroll's authority, so it must be for this buyer
        // and this figure. A split or buyer corrected after signing needs a
        // fresh signature, not a notice backed by the old one.
        if ($buyout->agreement && ! BuyoutAgreementIssuer::matches($buyout, $buyout->agreement)) {
            return 'admin/deployments/general.buyout_payroll_agreement_stale';
        }

        $invoice = $buyout->payment_method === 'invoice';
        $key = $invoice ? self::INVOICE_KEY : self::KEY;
        $to = $invoice
            ? EmailTemplate::recipientsFor($key, config('leasing.buyout_invoice_to'))
            : EmailTemplate::recipientsFor($key, config('leasing.buyout_payroll_to'));

        if (! $to) {
            return 'admin/deployments/general.buyout_payroll_no_recipients';
        }

        $cc = EmailTemplate::ccFor($key, config($invoice ? 'leasing.buyout_invoice_cc' : 'leasing.buyout_payroll_cc'));
        $buyout->loadMissing(['asset', 'buyer']);
        if ($buyout->buyer && filled($buyout->buyer->email)) {
            $cc[] = $buyout->buyer->email;
        }
        $cc = array_values(array_diff(array_unique(array_filter($cc)), $to));

        try {
            Mail::to($to)->cc($cc)->send(new AssetBuyoutPayrollMail($buyout, $invoice));
        } catch (\Throwable $e) {
            Log::error('Buyout payroll notice failed for buyout '.$buyout->id.': '.$e->getMessage());

            return 'admin/deployments/general.buyout_payroll_failed';
        }

        if ($buyout->payment_method === null) {
            $buyout->forceFill(['payment_method' => 'payroll_deduction'])->save();
        }

        $buyout->agreement?->markSentToPayroll($actor?->id);

        $this->log($buyout, $to, $actor, $invoice);

        return null;
    }

    private function log(AssetBuyout $buyout, array $to, ?User $actor, bool $invoice): void
    {
        $asset = $buyout->asset;

        if (! $asset) {
            return;
        }

        $log = new Actionlog;
        $log->item_type = Asset::class;
        $log->item_id = $asset->id;
        $log->setAttribute('created_by', $actor?->id);
        $log->company_id = $asset->company_id;
        $log->note = trans($invoice ? 'admin/deployments/general.buyout_log_invoice' : 'admin/deployments/general.buyout_log_payroll', [
            'amount' => number_format((float) ($buyout->buyer_amount ?? $buyout->quote_total), 2),
            'to' => implode(', ', $to),
        ]);
        $log->logaction(ActionType::BuyoutRequested->value);
    }
}
