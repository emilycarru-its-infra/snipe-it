<?php

namespace App\Mail;

use App\Models\OrderInvoice;
use App\Services\Leasing\OkayToPay;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/** One transport for ready invoices on one order; reservations remain per invoice. */
class OkayToPayBatchMail extends OkayToPayMail
{
    public Collection $invoices;

    public function __construct(Collection $invoices)
    {
        $this->invoices = $invoices->sortBy('id')->values();
        if ($this->invoices->isEmpty() || $this->invoices->pluck('order_id')->unique()->count() !== 1) {
            throw new \InvalidArgumentException('A payment email must contain invoices for one order.');
        }
        parent::__construct($this->invoices->first());
    }

    public function envelope(): Envelope
    {
        if ($this->frozenEnvelope) {
            return $this->frozenEnvelope;
        }
        $from = $this->fromAddress();

        return new Envelope(from: $from, replyTo: [$from], subject: $this->overriddenSubject(OkayToPay::KEY,
            trans('mail.okay_to_pay_batch_subject', ['supplier' => $this->invoice->order->supplier?->name,
                'order' => $this->invoice->order->order_number])));
    }

    public function content(): Content
    {
        if ($this->frozenHtml !== null) {
            return new Content(htmlString: $this->frozenHtml);
        }
        $sections = $this->invoices->map(function (OrderInvoice $invoice) {
            $single = new OkayToPayMail($invoice);
            $data = $single->viewData();
            $content = $single->bodyContent(OkayToPay::KEY, 'notifications.markdown.okay-to-pay', $data);
            $data['custom_body'] = $content->markdown === 'mail.markdown.dynamic' ? ($content->with['body'] ?? null) : null;

            return $data;
        });

        return new Content(markdown: 'notifications.markdown.okay-to-pay-batch', with: [
            'sections' => $sections, 'order' => $this->invoice->order,
            'total' => OkayToPay::money($this->invoices->sum('total')),
            'signature' => $this->fromAddress()->name ?: $this->fromAddress()->address,
        ]);
    }

    public function csv(): string
    {
        if ($this->frozenCsv !== null) {
            return $this->frozenCsv;
        }
        $csv = '';
        foreach ($this->invoices as $index => $invoice) {
            $part = (new OkayToPayMail($invoice))->csv();
            $csv .= $index === 0 ? $part : substr($part, strpos($part, "\n") + 1);
        }

        return $csv;
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->csv(), 'ok-to-pay-order-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $this->invoice->order->order_number).'.csv')->withMime('text/csv')];
    }

    public function freeze(): array
    {
        return parent::freeze() + ['invoice_ids' => $this->invoices->pluck('id')->all(),
            'invoice_numbers' => $this->invoices->pluck('invoice_number')->all()];
    }
}
