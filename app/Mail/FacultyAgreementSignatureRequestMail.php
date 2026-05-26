<?php

namespace App\Mail;

use App\Models\FacultyAgreement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Sent to the faculty member when their FacultyAgreement transitions
 * to `agreement_sent` (via the model's sendForSignature() helper or
 * direct stage edit in the UI). One email per agreement. The PDF is
 * attached when an unsigned copy has already been rendered by
 * `snipeit:faculty-pregen-pdfs`; otherwise the email just carries the
 * sign link.
 */
class FacultyAgreementSignatureRequestMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public FacultyAgreement $agreement;

    public function __construct(FacultyAgreement $agreement)
    {
        $this->agreement = $agreement->loadMissing(['user', 'asset.model']);
    }

    public function envelope(): Envelope
    {
        $subjectKey = match ($this->agreement->agreement_type) {
            'upgrade'            => 'mail.faculty_signature_request_upgrade',
            'lease_end_purchase' => 'mail.faculty_signature_request_buyout',
            default              => 'mail.faculty_signature_request_pickup',
        };

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: trans($subjectKey, [
                'name'      => $this->agreement->user->first_name ?? '',
                'asset_tag' => $this->agreement->asset->asset_tag ?? '',
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'notifications.markdown.faculty-agreement-signature-request',
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
                ->as('faculty-agreement-'.$this->agreement->id.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
