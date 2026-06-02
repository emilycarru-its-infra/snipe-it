<?php

namespace App\Mail;

use App\Models\ExhibitEmailTemplate;
use App\Models\ExhibitProject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one editable exhibit email template to a student, with the
 * project's {{merge_variables}} substituted into the subject + body.
 * Goes out through the M365 SMTP relay.
 */
class ExhibitNotificationMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public ExhibitProject $project;

    public ExhibitEmailTemplate $template;

    public string $renderedSubject;

    public string $renderedBody;

    public function __construct(ExhibitProject $project, ExhibitEmailTemplate $template)
    {
        $this->project = $project->loadMissing(['user', 'asset']);
        $this->template = $template;

        $rendered = $template->render($this->project);
        $this->renderedSubject = $rendered['subject'];
        $this->renderedBody = $rendered['body'];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->renderedSubject !== '' ? $this->renderedSubject : ($this->template->name ?? 'Exhibit'),
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
