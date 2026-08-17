<?php

namespace App\Mail;

use App\Models\ExhibitProject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One exhibit email to one student, with the project's
 * {{merge_variables}} substituted into the subject + body. The wording
 * arrives as plain strings — a stored template, or whatever the composer
 * sheet holds after the admin edited it — so the send path is the same
 * whether the letter was saved first or not. Goes out through the M365
 * SMTP relay.
 */
class ExhibitNotificationMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public ExhibitProject $project;

    public string $renderedSubject;

    public string $renderedBody;

    public bool $test;

    public function __construct(ExhibitProject $project, string $subject, string $body, bool $test = false)
    {
        $this->project = $project->loadMissing(['user', 'asset']);
        $this->test = $test;

        // Both token spellings resolve — {{var}} as the stored templates
        // are written, {{ var }} as the composer's field inserter writes.
        foreach ($this->project->mergeVariables() as $var => $value) {
            $subject = str_replace(['{{'.$var.'}}', '{{ '.$var.' }}'], $value, $subject);
            $body = str_replace(['{{'.$var.'}}', '{{ '.$var.' }}'], $value, $body);
        }

        $this->renderedSubject = $subject;
        $this->renderedBody = $body;
    }

    public function envelope(): Envelope
    {
        $subject = $this->renderedSubject !== '' ? $this->renderedSubject : 'Exhibit';

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: ($this->test ? '[TEST] ' : '').$subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'notifications.markdown.exhibit-notification',
            with: ['body' => $this->renderedBody],
        );
    }
}
