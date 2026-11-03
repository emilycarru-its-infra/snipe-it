<?php

namespace App\Services\Leasing;

use App\Models\Asset;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\OkayToPayAcceptance as Acceptance;
use App\Models\OkayToPayDelivery;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Receipt and acceptance are explicit acts, separate from invoice matching. */
class OkayToPayAcceptance
{
    public function snapshot(OrderInvoice $invoice): array
    {
        // Reload every business fact: an already-loaded relation can predate a webhook.
        $invoice->refresh()->load(['order', 'items.item', 'items.shipment']);
        $order = $invoice->order;
        $lines = $invoice->items->sortBy('id')->values()->map(function ($line) {
            $asset = $line->item instanceof Asset ? $line->item : null;

            return [
                $line->id, $line->order_id, $line->purchase_order_id, $line->shipment_id,
                $line->item_type, $line->item_id, $line->description, $line->quantity,
                $line->unit_cost, $line->warranty_cost, $line->received_at?->toISOString(),
                $line->shipment?->received_date?->toDateString(),
                $asset?->serial, $asset?->lessor_id, $asset?->lease_contract_id,
                $asset?->status_id, $asset?->status?->archived, $asset?->getRawOriginal('decommission_date'), $asset?->deleted_at?->toISOString(),
            ];
        })->all();
        $facts = [
            'policy' => 'receipt-acceptance-v1',
            'invoice' => [$invoice->id, $invoice->order_id, $invoice->purchase_order_id,
                $invoice->invoice_number, $invoice->invoice_date?->toDateString(),
                $invoice->invoice_type, $invoice->subtotal, $invoice->tax_gst,
                $invoice->tax_pst, $invoice->shipping, $invoice->total],
            'order' => [$order?->id, $order?->company_id, $order?->supplier_id, $order?->purchase_order_id,
                $order?->order_number, $order?->status, $order?->lease_schedule,
                $order?->funding_account, $order?->quote_total, $order?->quote_number, $order?->quote_confirmed_at?->toISOString()],
            'lines' => $lines,
        ];

        return $facts;
    }

    public function fingerprint(OrderInvoice $invoice): string
    {
        return hash('sha256', json_encode($this->snapshot($invoice), JSON_THROW_ON_ERROR));
    }

    public function lockFacts(OrderInvoice $invoice): void
    {
        Order::whereKey($invoice->order_id)->lockForUpdate()->first();
        $lines = OrderItem::where('order_id', $invoice->order_id)->orderBy('id')->lockForUpdate()->get();
        $ids = $lines->where('item_type', Asset::class)->pluck('item_id')->filter()->unique()->sort()->values();
        Asset::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        OrderShipment::where('order_id', $invoice->order_id)->orderBy('id')->lockForUpdate()->get();
        $order = $invoice->order()->first();
        $lessor = OkayToPay::lessor();
        Supplier::whereIn('id', array_filter([$order?->supplier_id, $lessor?->id]))->orderBy('id')->lockForUpdate()->get();
        EmailTemplate::where('key', OkayToPay::KEY)->lockForUpdate()->first();
    }

    public function latest(OrderInvoice $invoice): ?Acceptance
    {
        return Acceptance::where('invoice_id', $invoice->id)->latest('id')->first();
    }

    public function reasons(OrderInvoice $invoice): array
    {
        $current = $this->fingerprint($invoice);
        $acceptance = $this->latest($invoice);
        if (! $acceptance || $acceptance->decision !== 'accept') {
            return [trans('admin/purchase-orders/general.okp_reason_acceptance_missing')];
        }
        if (! hash_equals($acceptance->fingerprint, $current)) {
            return [trans('admin/purchase-orders/general.okp_reason_acceptance_stale')];
        }

        return [];
    }

    public function record(OrderInvoice $invoice, User $actor, array $data): Acceptance
    {
        $data = Validator::make($data, [
            'decision' => 'required|in:accept,revoke',
            'fingerprint' => 'required|string|size:64',
            'expected_acceptance_id' => 'nullable|integer|min:1',
            'received_in_full' => 'required_if:decision,accept|accepted_if:decision,accept',
            'satisfactory' => 'required_if:decision,accept|accepted_if:decision,accept',
            'receipt_reference' => 'required_if:decision,accept|nullable|string|max:1000',
            'acceptance_reference' => 'required_if:decision,accept|nullable|string|max:1000',
        ])->validate();
        if ($data['decision'] === 'accept' && (trim($data['receipt_reference']) === '' || trim($data['acceptance_reference']) === '')) {
            throw ValidationException::withMessages(['receipt_reference' => trans('validation.required', ['attribute' => 'evidence'])]);
        }

        return DB::transaction(function () use ($invoice, $actor, $data) {
            Order::whereKey($invoice->order_id)->lockForUpdate()->first();
            $invoice = OrderInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->lockFacts($invoice);
            abort_unless(Company::isCurrentUserHasAccess($invoice->order), 404);
            OkayToPayHistory::protect($invoice);
            if ($data['decision'] === 'accept' && OkayToPayHistory::blocked($invoice)) {
                throw ValidationException::withMessages(['decision' => trans('admin/purchase-orders/general.okp_reason_historical_settlement')]);
            }
            if ($invoice->approval_status === 'disputed' && $data['decision'] === 'accept') {
                throw ValidationException::withMessages(['decision' => trans('admin/purchase-orders/general.okp_reason_disputed')]);
            }
            $current = $this->fingerprint($invoice);
            $latest = $this->latest($invoice);
            if ($invoice->okp_status === OkayToPay::SENT || $invoice->okp_sent_at || ($invoice->okp_status === null && $invoice->approval_status === 'approved') || OkayToPayDelivery::where('invoice_id', $invoice->id)->exists()) {
                throw ValidationException::withMessages(['decision' => trans('admin/purchase-orders/general.okp_reason_delivery_reserved')]);
            }
            if (! hash_equals($current, $data['fingerprint']) || (int) ($data['expected_acceptance_id'] ?? 0) !== (int) ($latest->id ?? 0)) {
                throw ValidationException::withMessages(['fingerprint' => trans('admin/purchase-orders/general.okp_reason_acceptance_stale')]);
            }
            if ($data['decision'] === 'accept') {
                $okayToPay = app(OkayToPay::class);
                if ($okayToPay->mode() === 'off' || ! $okayToPay->applies($invoice)) {
                    throw ValidationException::withMessages(['decision' => trans('admin/purchase-orders/general.okp_reason_ineligible')]);
                }
                $reasons = $okayToPay->mismatches($invoice, requireAcceptance: false);
                if ($reasons) {
                    throw ValidationException::withMessages(['decision' => implode(' ', $reasons)]);
                }
            }
            $acceptance = Acceptance::create([
                'invoice_id' => $invoice->id,
                'decision' => $data['decision'],
                'fingerprint' => $current,
                'snapshot' => $this->snapshot($invoice),
                'actor_id' => $actor->id,
                'actor_name' => mb_substr((string) $actor->full_name, 0, 191),
                'receipt_reference' => $data['decision'] === 'accept' ? trim($data['receipt_reference']) : null,
                'acceptance_reference' => $data['decision'] === 'accept' ? trim($data['acceptance_reference']) : null,
                'created_at' => now(),
            ]);
            $invoice->forceFill(['approval_status' => $invoice->approval_status === 'disputed' ? 'disputed' : 'pending', 'approved_at' => null, 'approved_by' => null])->save();
            app(OkayToPay::class)->consider($invoice);

            return $acceptance;
        });
    }
}
