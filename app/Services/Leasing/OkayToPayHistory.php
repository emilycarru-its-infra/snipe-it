<?php

namespace App\Services\Leasing;

use App\Models\OkayToPayLegacySettlement;
use App\Models\Order;
use App\Models\OrderInvoice;

/** Preserve known settlement history across ordinary accounting edits and deletes. */
class OkayToPayHistory
{
    public static function identityKey(?int $supplierId, ?string $invoiceNumber): ?string
    {
        if (! $supplierId || trim((string) $invoiceNumber) === '') {
            return null;
        }

        return hash('sha256', $supplierId."\n".mb_strtolower(trim($invoiceNumber)));
    }

    public static function protect(OrderInvoice $invoice, bool $original = false): void
    {
        $facts = $original ? $invoice->getRawOriginal() : $invoice->getAttributes();
        $sent = ($facts['okp_status'] ?? null) === 'sent' || ! empty($facts['okp_sent_at']);
        $approved = ($facts['approval_status'] ?? null) === 'approved' && empty($facts['okp_status']);
        if (! $invoice->getKey() || (! $sent && ! $approved)) {
            return;
        }
        // The original order identity is preserved before an invoice changes.
        // A missing/deleted vendor stays unknown and conservatively blocks the
        // same invoice number at any vendor instead of inventing an identity.
        $supplierId = Order::withTrashed()->whereKey($facts['order_id'] ?? null)->value('supplier_id');
        $number = (string) ($facts['invoice_number'] ?? '');
        OkayToPayLegacySettlement::firstOrCreate(['invoice_id' => $invoice->getKey()], [
            'supplier_id' => $supplierId,
            'invoice_number' => $number,
            'invoice_key' => self::identityKey($supplierId, $number),
            'reason' => $sent ? 'previously_sent' : 'legacy_approved',
            'snapshot' => array_intersect_key($facts, array_flip([
                'order_id', 'invoice_number', 'invoice_date', 'approval_status', 'approved_at',
                'approved_by', 'okp_status', 'okp_sent_at', 'okp_sent_to',
            ])),
            'created_at' => now(),
        ]);
    }

    public static function blocked(OrderInvoice $invoice): bool
    {
        $supplierId = $invoice->order?->supplier_id;
        $number = mb_strtolower(trim((string) $invoice->invoice_number));
        $key = self::identityKey($supplierId, $invoice->invoice_number);

        return OkayToPayLegacySettlement::where(function ($query) use ($invoice, $number, $key) {
            $query->where('invoice_id', $invoice->id);
            if ($key !== null) {
                $query->orWhere('invoice_key', $key);
            }
            if ($number !== '') {
                $query->orWhere(fn ($unknown) => $unknown->whereNull('supplier_id')
                    ->whereRaw('LOWER(TRIM(invoice_number)) = ?', [$number]));
            }
        })->exists();
    }
}
