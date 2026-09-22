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
 * Tells payroll to deduct the buyer's share once the buyer has said yes.
 *
 * Fired by the approval transition, so the step that used to be a forwarded
 * mail thread happens the moment the record says it should. Skipped when the
 * buyer settles some other way (`invoice`, `other`); an unset payment method
 * is treated as payroll, since that is the usual route, and is stamped as
 * such once payroll has been told.
 *
 * Addressing: To the payroll list (Settings → Emails override, else the
 * `leasing.buyout_payroll_to` default), Cc the configured team list plus the
 * buyer — they asked what happens next, and this is the answer.
 */
class BuyoutPayrollNotifier
{
    public const KEY = 'request.asset_buyout_payroll';

    /**
     * Send the notice. Returns a translation key describing why it was not
     * sent, or null on success. Never throws: a mail failure must not undo
     * the approval that triggered it.
     */
    public function send(AssetBuyout $buyout, ?User $actor): ?string
    {
        if (in_array($buyout->payment_method, ['invoice', 'other'], true)) {
            return 'admin/deployments/general.buyout_payroll_not_payroll';
        }

        if ($buyout->quote_total === null && $buyout->buyer_amount === null) {
            return 'admin/deployments/general.buyout_payroll_no_quote';
        }

        $to = EmailTemplate::recipientsFor(self::KEY, config('leasing.buyout_payroll_to'));

        if (! $to) {
            return 'admin/deployments/general.buyout_payroll_no_recipients';
        }

        $cc = EmailTemplate::ccFor(self::KEY, config('leasing.buyout_payroll_cc'));
        $buyout->loadMissing(['asset', 'buyer']);
        if ($buyout->buyer && filled($buyout->buyer->email)) {
            $cc[] = $buyout->buyer->email;
        }
        $cc = array_values(array_diff(array_unique(array_filter($cc)), $to));

        try {
            Mail::to($to)->cc($cc)->send(new AssetBuyoutPayrollMail($buyout));
        } catch (\Throwable $e) {
            Log::error('Buyout payroll notice failed for buyout '.$buyout->id.': '.$e->getMessage());

            return 'admin/deployments/general.buyout_payroll_failed';
        }

        if ($buyout->payment_method === null) {
            $buyout->forceFill(['payment_method' => 'payroll_deduction'])->save();
        }

        $this->log($buyout, $to, $actor);

        return null;
    }

    private function log(AssetBuyout $buyout, array $to, ?User $actor): void
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
        $log->note = trans('admin/deployments/general.buyout_log_payroll', [
            'amount' => number_format((float) ($buyout->buyer_amount ?? $buyout->quote_total), 2),
            'to' => implode(', ', $to),
        ]);
        $log->logaction(ActionType::BuyoutRequested->value);
    }
}
