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
 * generated from the same captured text shown on the signing page.
 * Persisted requests never trust an older cached attachment.
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
        return $this->bodyContent('agreement.signature_request', 'notifications.markdown.user-agreement-signature-request', [
                'agreement' => $this->agreement,
                'variables' => $this->agreement->mergeVariables(),
            ]);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        // Settings previews use a synthetic, unsaved agreement and have no attachment.
        if (! $this->agreement->exists) {
            return [];
        }

        $this->agreement->refresh();
        $this->agreement->assertKnownPrice();
        $path = $this->agreement->storeUnsignedPdf();
        if (! $path) {
            throw new \RuntimeException('An unsigned agreement attachment could not be generated.');
        }

        return [
            Attachment::fromStorage($path)
                ->as('user-agreement-'.$this->agreement->id.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
