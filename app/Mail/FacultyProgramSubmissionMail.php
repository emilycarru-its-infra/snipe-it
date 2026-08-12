<?php

namespace App\Mail;

use App\Models\UserAgreement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * What a faculty member actually answered on the intake form, mailed to
 * the program the moment they submit.
 *
 * Until this existed the form recorded a UserAgreement and told nobody:
 * a submission was only visible to someone who went looking at the
 * ledger, so the first anyone heard of an application was usually the
 * applicant emailing to ask why nothing had happened. The answers matter
 * on their own too — the trade-in decision is the one question on the
 * form that costs real money, and payroll deduction is a commitment
 * somebody has to act on.
 *
 * Goes to the program, not the applicant: they already land on a success
 * page and hear from the store as their order moves.
 */
class FacultyProgramSubmissionMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public const KEY = 'agreements.faculty_program_submitted';

    public function __construct(
        public UserAgreement $pickup,
        public ?UserAgreement $buyout = null,
        public bool $updated = false,
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->pickup->user?->present()->fullName ?? trans('general.unknown');

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            // An edit and a first submission read very differently to
            // whoever is working the queue, so the subject says which.
            subject: $this->overriddenSubject(self::KEY, trans(
                $this->updated ? 'mail.faculty_program_updated_subject' : 'mail.faculty_program_submitted_subject',
                ['name' => $name],
            )),
        );
    }

    public function content(): Content
    {
        $this->pickup->loadMissing('user', 'asset.model');
        $this->buyout?->loadMissing('asset');

        return $this->bodyContent(self::KEY, 'notifications.markdown.faculty-program-submission', [
            'pickup' => $this->pickup,
            'buyout' => $this->buyout,
            'updated' => $this->updated,
            'applicant' => $this->pickup->user,
            'asset' => $this->pickup->asset,
        ]);
    }
}
