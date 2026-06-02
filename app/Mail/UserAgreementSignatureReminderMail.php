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
 * Sent by the snipeit:user-agreement-signature-reminders artisan
 * command when a UserAgreement has been at lifecycle_stage
 * `agreement_sent` for at least
 * config('forms.signature_reminders.interval_days') and is below
 * `max_reminders`. Same body shape as the initial request mail; the
 * subject line is a distinct "reminder N" variant so the user can
 * spot it in a thread.
 */
class UserAgreementSignatureReminderMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public UserAgreement $agreement;
    public int $reminderNumber;

    public function __construct(UserAgreement $agreement, int $reminderNumber)
    {
        $this->agreement      = $agreement->loadMissing(['user', 'asset.model']);
        $this->reminderNumber = $reminderNumber;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->overriddenSubject('agreement.signature_reminder', trans('mail.user_agreement_signature_reminder_subject', [
                'name'      => $this->agreement->user->first_name ?? '',
                'asset_tag' => $this->agreement->asset->asset_tag ?? '',
                'number'    => $this->reminderNumber,
            ])),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'notifications.markdown.user-agreement-signature-reminder',
            with: [
                'agreement'      => $this->agreement,
                'variables'      => $this->agreement->mergeVariables(),
                'reminderNumber' => $this->reminderNumber,
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
