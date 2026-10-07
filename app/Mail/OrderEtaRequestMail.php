<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderEtaRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "What is the ETA for order X?" — to the vendor's reps, the team copied.
 *
 * Short on purpose: it replaces a two-line email somebody typed by hand. The
 * numbers their desk searches on come first, then only the lines still to
 * arrive, then whatever the sender added. Replies go to the sender, who is
 * also copied, so the answer reaches a person rather than the system address.
 */
class OrderEtaRequestMail extends BaseMailable
{
    use Queueable, SerializesModels;

    /**
     * Only the order is public: a Mailable hands its public properties to the
     * view, and the body is given exactly what it prints instead.
     */
    public function __construct(
        public Order $order,
        protected ?User $sender = null,
        protected ?string $subjectLine = null,
        protected string $note = '',
        protected bool $test = false,
    ) {}

    /**
     * The subject when the sender did not write one: Settings → Emails'
     * override if there is one (its {{reference}} merged), else the default.
     */
    public static function defaultSubject(Order $order): string
    {
        $reference = $order->etaReference();
        $default = trans('mail.order_eta_request_subject', ['reference' => $reference]);

        if (BaseMailable::$ignoreOverrides) {
            return $default;
        }

        try {
            $override = EmailTemplate::forKey(OrderEtaRequest::KEY);
            if ($override && filled($override->subject)) {
                return trim(EmailTemplateRenderer::render($override->subject, [
                    'reference' => $reference,
                    'order' => $order,
                    'supplier' => $order->supplier,
                ]));
            }
        } catch (\Throwable $e) {
            // fall back to the built-in subject
        }

        return $default;
    }

    public function envelope(): Envelope
    {
        // As on the order mail: an explicit sender only when one is configured,
        // because Address(null) throws where MAIL_FROM_ADDR is unset.
        $from = config('mail.from.address');
        $replyTo = $this->sender?->email
            ? [new Address($this->sender->email, $this->sender->present()->fullName)]
            : [];

        return new Envelope(
            from: $from ? new Address($from, config('mail.from.name')) : null,
            replyTo: $replyTo,
            subject: ($this->test ? trans('mail.store_vendor_order_test_prefix').' ' : '')
                .($this->subjectLine ?: self::defaultSubject($this->order)),
        );
    }

    public function content(): Content
    {
        $this->order->loadMissing('supplier', 'purchaseOrder');

        return $this->bodyContent(OrderEtaRequest::KEY, 'notifications.markdown.order-eta-request', [
            'order' => $this->order,
            'supplier' => $this->order->supplier,
            'reference' => $this->order->etaReference(),
            'purchase_order' => $this->order->purchaseOrder?->po_number,
            'lines' => $this->order->outstandingLines(),
            'note' => $this->note,
            'sender' => (string) $this->sender?->present()->fullName,
        ]);
    }
}
