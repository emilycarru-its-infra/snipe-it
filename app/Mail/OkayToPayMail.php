<?php

namespace App\Mail;

use App\Models\Asset;
use App\Models\OrderInvoice;
use App\Services\Leasing\OkayToPay;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Our "OK to pay" to the lessor for one vendor invoice on a lease schedule —
 * the reply the lessor's approval letter asks for, sent before it has to ask.
 * Lists what was billed so the lessor can match it to the invoice it holds,
 * and attaches the same lines as a CSV for their own reconciliation.
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
        return [
            Attachment::fromData(fn () => $this->csv(), 'ok-to-pay-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $this->invoice->invoice_number).'.csv')
                ->withMime('text/csv'),
        ];
    }

    /**
     * The billed lines as one row each, with the invoice, schedule and order
     * repeated on every row so the file stands on its own once it is imported.
     */
    public function csv(): string
    {
        $order = $this->invoice->order;
        $out = fopen('php://temp', 'r+');

        fputcsv($out, [
            'Invoice', 'Invoice Date', 'Vendor', 'Equipment Schedule', 'Order',
            'Asset Tag', 'Serial', 'Manufacturer', 'Model', 'Model Number', 'Description',
            'Quantity', 'Unit Cost', 'Soft Cost', 'Line Total',
        ], escape: '');

        foreach ($this->invoice->items as $line) {
            $asset = $line->item instanceof Asset ? $line->item : null;

            fputcsv($out, [
                self::cell($this->invoice->invoice_number),
                optional($this->invoice->invoice_date)->toDateString(),
                self::cell($order?->supplier?->name),
                self::cell($order?->lease_schedule),
                self::cell($order?->order_number),
                self::cell($asset?->asset_tag),
                self::cell($asset?->serial),
                self::cell($asset?->model?->manufacturer?->name),
                self::cell($asset?->model?->name),
                self::cell($asset?->model?->model_number),
                self::cell($line->description ?: ($line->item->name ?? '')),
                (int) $line->quantity,
                number_format((float) $line->unit_cost, 2, '.', ''),
                number_format((float) $line->warranty_cost, 2, '.', ''),
                number_format($line->lineTotal(), 2, '.', ''),
            ], escape: '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
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

    /**
     * A text cell that a spreadsheet will not read as a formula. The lines
     * come from the vendor's invoice and the file is opened by the lessor, so
     * a description starting with "=" must stay text on their side.
     */
    private static function cell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
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
