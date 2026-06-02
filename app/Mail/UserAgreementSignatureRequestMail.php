<?php

namespace App\Mail;

use App\Models\UserAgreement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Sent to the assigned user when their UserAgreement transitions
 * to `agreement_sent` (via the model's sendForSignature() helper or
 * direct stage edit in the UI). One email per agreement. The PDF is
 * attached when an unsigned copy has already been rendered by
 * `snipeit:user-pregen-pdfs`; otherwise the email just carries the
 * sign link.
 */
class UserAgreementSignatureRequestMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public UserAgreement $agreement;

    public function __construct(UserAgreement $agreement)
    {
        $this->agreement = $agreement->loadMissing(['user', 'asset.model']);
    }

    public function envelope(): Envelope
    {
        $subjectKey = match ($this->agreement->agreement_type) {
            'upgrade'  => 'mail.user_agreement_signature_request_upgrade',
            'purchase' => 'mail.user_agreement_signature_request_purchase',
            default    => 'mail.user_agreement_signature_request_pickup',
        };

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->overriddenSubject('agreement.signature_request', trans($subjectKey, [
                'name'      => $this->agreement->user->first_name ?? '',
                'asset_tag' => $this->agreement->asset->asset_tag ?? '',
            ])),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'notifications.markdown.user-agreement-signature-request',
            with: [
                'agreement' => $this->agreement,
                'variables' => $this->agreement->mergeVariables(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->agreement->pdf_path) {
            return [];
        }

        if (! Storage::exists($this->agreement->pdf_path)) {
            return [];
        }

        return [
            Attachment::fromStorage($this->agreement->pdf_path)
                ->as('user-agreement-'.$this->agreement->id.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
