<?php

namespace App\Mail;

use App\Models\DeploymentWave;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * The note that goes to the people in a deployment wave.
 *
 * Written per recipient rather than once for the group, because what a person
 * needs to know is about their own machine: which laptop they have now, when its
 * lease ends, what they are being offered. A single broadcast would make every
 * reader work out which parts applied to them — and for the Faculty Laptop
 * Program that is the whole content of the email.
 *
 * So the subject and body are templates, rendered against each recipient's own
 * facts through the same Handlebars renderer the admin email overrides use. The
 * body is composed in the browser, and it starts from a program template rather
 * than a blank box, since these are annual emails whose wording barely changes.
 */
class DeploymentWaveMail extends BaseMailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, \App\Models\Asset>  $assets  the recipient's devices in this wave
     * @param  array<string, mixed>  $context  merge values, already resolved for this recipient
     */
    public function __construct(
        public DeploymentWave $wave,
        public User $recipient,
        public string $subjectTemplate,
        public string $bodyTemplate,
        public Collection $assets,
        public array $context = [],
        public bool $test = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = EmailTemplateRenderer::render($this->subjectTemplate, $this->context);

        // Only name an explicit sender when one is configured — with
        // MAIL_FROM_ADDR unset (the test environments), Address(null) throws.
        $from = config('mail.from.address');

        return new Envelope(
            from: $from ? new Address($from, config('mail.from.name')) : null,
            subject: ($this->test ? trans('mail.store_vendor_order_test_prefix').' ' : '').$subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'notifications.markdown.deployment-wave',
            with: [
                'wave' => $this->wave,
                'recipient' => $this->recipient,
                'assets' => $this->assets,
                'body' => EmailTemplateRenderer::render($this->bodyTemplate, $this->context),
                'context' => $this->context,
            ],
        );
    }
}
