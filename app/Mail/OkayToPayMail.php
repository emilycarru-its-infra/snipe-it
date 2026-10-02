<?php

namespace App\Mail;

use App\Models\Asset;
use App\Models\OrderInvoice;
use App\Services\Leasing\OkayToPay;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Our "OK to pay" to the lessor for one vendor invoice on a lease schedule —
 * the reply the lessor's approval letter asks for, sent before it has to ask.
 * Lists what was billed so the lessor can match it to the invoice it holds.
 * OkayToPay decides when it goes and addresses it.
 */
class OkayToPayMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public OrderInvoice $invoice;

    public function __construct(OrderInvoice $invoice)
    {
        $this->invoice = $invoice->loadMissing(['order.supplier', 'items.item']);
    }

    public function envelope(): Envelope
    {
        $from = $this->fromAddress();

        return new Envelope(
            from: $from,
            replyTo: [$from],
            subject: $this->overriddenSubject(OkayToPay::KEY, trans('mail.okay_to_pay_subject', $this->subjectVars())),
        );
    }

    public function content(): Content
    {
        $order = $this->invoice->order;

        return $this->bodyContent(OkayToPay::KEY, 'notifications.markdown.okay-to-pay', [
            'invoice' => $this->invoice,
            'order' => $order,
            'supplier' => $order?->supplier,
            'schedule' => $order?->lease_schedule,
            'lines' => $this->lines(),
            'amounts' => [
                'subtotal' => OkayToPay::money($this->invoice->subtotal),
                'gst' => OkayToPay::money($this->invoice->tax_gst),
                'pst' => OkayToPay::money($this->invoice->tax_pst),
                'shipping' => OkayToPay::money($this->invoice->shipping),
                'total' => OkayToPay::money($this->invoice->total),
            ],
            'signature' => $this->fromAddress()->name ?: $this->fromAddress()->address,
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [];
    }

    /**
     * One row per billed line, with the serial where the line is a device.
     *
     * @return array<int, array{description: string, quantity: int, serial: ?string, amount: string}>
     */
    private function lines(): array
    {
        return $this->invoice->items->map(fn ($line) => [
            'description' => (string) ($line->description ?: ($line->item->name ?? '')),
            'quantity' => (int) $line->quantity,
            'serial' => $line->item instanceof Asset ? $line->item->serial : null,
            'amount' => OkayToPay::money($line->lineTotal()),
        ])->values()->all();
    }

    /** @return array<string, string> */
    private function subjectVars(): array
    {
        $order = $this->invoice->order;

        return [
            'supplier' => $order->supplier->name ?? '',
            'invoice' => $this->invoice->invoice_number,
            'schedule' => (string) ($order->lease_schedule ?? ''),
            'total' => OkayToPay::money($this->invoice->total),
        ];
    }

    /**
     * The lessor knows its approver by name, so this can come from a person
     * rather than the app's service address; unset, it falls back to that.
     */
    private function fromAddress(): Address
    {
        $address = (string) OkayToPay::setting('from_address');

        return filled($address)
            ? new Address($address, (string) OkayToPay::setting('from_name') ?: null)
            : new Address(config('mail.from.address'), config('mail.from.name'));
    }
}
