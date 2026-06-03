<?php

namespace App\Mail;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Sent by `snipeit:contract-renewals` for one of three windows:
 *   - "30d"     : end_date inside 30 days
 *   - "14d"     : end_date inside 14 days (more urgent)
 *   - "expired" : end_date already past
 *
 * Recipients are determined by the console command — either the
 * contract's `admin_user` (per-row) or, when that is null, the
 * Setting::alert_email fallback list.
 */
class ContractRenewalAlertMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public Collection $contracts;
    public string $window;

    public function __construct(Collection $contracts, string $window)
    {
        $this->contracts = $contracts;
        $this->window    = $window;
    }

    public function envelope(): Envelope
    {
        $subjectKey = match ($this->window) {
            '14d'     => 'mail.contract_renewal_alert_14d',
            'expired' => 'mail.contract_renewal_alert_expired',
            default   => 'mail.contract_renewal_alert_30d',
        };

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->overriddenSubject('report.contract_renewal', trans($subjectKey, ['count' => $this->contracts->count()])),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'notifications.markdown.contract-renewal-alert',
            with: [
                'contracts' => $this->contracts,
                'window'    => $this->window,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
