<?php

namespace App\Mail;

use App\Models\AssetBuyout;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to payroll the moment a buyer approves a buyout, asking for the
 * deduction that makes ECU whole: the lessor invoices ECU for the device, and
 * the buyer's share comes back through their pay.
 *
 * Carries everything payroll needs without opening the thread — who, how
 * much, and what for (the lessor's quote, the device, the lease it comes off).
 * BuyoutPayrollNotifier addresses it.
 */
class AssetBuyoutPayrollMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public AssetBuyout $buyout;

    /** Lease facts resolved from the asset's native lease columns, for the body. */
    public array $lease;

    public function __construct(AssetBuyout $buyout)
    {
        $this->buyout = $buyout->loadMissing(['asset.model', 'lessor', 'buyer']);

        $asset = $this->buyout->asset;
        $this->lease = [
            'contract_id' => $asset?->lease_contract_id,
            'end_date' => optional($asset?->leaseEndDate())->toDateString(),
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            replyTo: [new Address(config('leasing.buyout_request_reply_to'))],
            subject: $this->overriddenSubject('request.asset_buyout_payroll', trans('mail.asset_buyout_payroll_subject', [
                'buyer' => $this->buyerName(),
                'asset_tag' => $this->buyout->asset->asset_tag ?? '',
            ])),
        );
    }

    public function content(): Content
    {
        return $this->bodyContent('request.asset_buyout_payroll', 'notifications.markdown.asset-buyout-payroll', [
            'buyout' => $this->buyout,
            'asset' => $this->buyout->asset,
            'buyer' => $this->buyout->buyer,
            'buyer_name' => $this->buyerName(),
            'lessor' => $this->buyout->lessor,
            'lease' => $this->lease,
            'amounts' => [
                'quote' => self::money($this->buyout->quote_amount),
                'remaining_rent' => self::money($this->buyout->remaining_rent),
                'quote_total' => self::money($this->buyout->quote_total),
                'deduction' => self::money($this->buyout->buyer_amount ?? $this->buyout->quote_total),
                'ecu' => self::money($this->buyout->ecu_amount),
            ],
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [];
    }

    private function buyerName(): string
    {
        $buyer = $this->buyout->buyer;

        return $buyer ? ($buyer->getFullNameAttribute() ?: (string) $buyer->email) : '';
    }

    private static function money($value): ?string
    {
        return $value === null ? null : '$'.number_format((float) $value, 2);
    }
}
