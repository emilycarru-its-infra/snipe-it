<?php

namespace App\Services\Leasing;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\OkayToPayDelivery;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\Preferences;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Authorized inventory actions supply receiving evidence, never imported status alone. */
class OkayToPayInventoryReceipt
{
    private const SOURCE = 'inventory-receipt-v1';

    public function enabled(): bool
    {
        return OkayToPay::setting('acceptance_mode') === 'inventory';
    }

    public function received(Asset $asset): bool
    {
        $ids = array_merge(
            Preferences::statusIds('status.store_journey.inventoried'),
            Preferences::statusIds('status.store_journey.provisioned'),
            Preferences::statusIds('status.agreement_handover'),
        );

        return in_array((int) $asset->status_id, $ids, true)
            && ! $asset->deleted_at && ! $asset->decommission_date && ! $asset->status?->archived;
    }

    public function invalidate(Asset $asset, bool $force = false): void
    {
        if (! $force && ! $asset->isDirty(['serial', 'status_id', 'company_id', 'lessor_id', 'lease_contract_id', 'decommission_date', 'deleted_at'])) {
            return;
        }
        if (! Actionlog::where('item_type', Asset::class)->where('item_id', $asset->id)
            ->where('log_meta', 'like', '%"'.self::SOURCE.'"%')->exists()) {
            return;
        }
        $log = new Actionlog;
        $log->forceFill([
            'item_type' => Asset::class, 'item_id' => $asset->id, 'created_by' => Auth::id(),
            'action_date' => now(), 'note' => 'Inventory receipt invalidated by equipment changes.',
            'log_meta' => json_encode([self::SOURCE => ['old' => null, 'new' => null]], JSON_THROW_ON_ERROR),
        ]);
        $log->logaction('update');
    }

    /** Called only for a status transition or a fresh checkout, after the asset save. */
    public function capture(Asset $asset): void
    {
        $actor = Auth::user();
        if (! $this->enabled() || $asset->imported || ! $actor instanceof User || ! $this->received($asset)
            || blank($asset->serial) || ! Gate::forUser($actor)->allows('procurement.edit')) {
            return;
        }

        // Passport service keys and console/import jobs cannot attest on a person's behalf.
        if (! Auth::guard('web')->user()?->is($actor) && ! Auth::guard('oidc')->user()?->is($actor)) {
            return;
        }

        $log = new Actionlog;
        $log->forceFill([
            'item_type' => Asset::class,
            'item_id' => $asset->id,
            'created_by' => $actor->id,
            'action_date' => now(),
            'note' => 'Inventory receipt: equipment and its associated coverage received in good order.',
            'log_meta' => json_encode([self::SOURCE => ['old' => null, 'new' => $this->identity($asset)]], JSON_THROW_ON_ERROR),
        ]);
        if (! $log->logaction('update')) {
            return;
        }

        // Other lines of the invoice, and the enclosing checkout transaction, must finish first.
        defer(function () use ($asset) {
            $ids = OrderItem::where('item_type', Asset::class)->where('item_id', $asset->id)
                ->whereNotNull('invoice_id')->distinct()->pluck('invoice_id');
            foreach (OrderInvoice::whereIn('id', $ids)->get() as $invoice) {
                app(OkayToPay::class)->consider($invoice);
            }
        });
    }

    /** Also runs on later invoice ingest, so separate coverage invoices reuse actual receipt. */
    public function acceptIfReady(OrderInvoice $invoice): void
    {
        if (! $this->enabled()) {
            return;
        }

        DB::transaction(function () use ($invoice) {
            Order::whereKey($invoice->order_id)->lockForUpdate()->first();
            $invoice = OrderInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            app(OkayToPayAcceptance::class)->lockFacts($invoice);
            $this->acceptLocked($invoice);
        });
    }

    private function acceptLocked(OrderInvoice $invoice): void
    {
        if (OkayToPayDelivery::where('invoice_id', $invoice->id)->exists()) {
            return;
        }

        $acceptance = app(OkayToPayAcceptance::class);
        $latest = $acceptance->latest($invoice);
        if ($latest && ($latest->decision !== 'accept'
            || ! str_starts_with((string) $latest->acceptance_reference, self::SOURCE.':')
            || $acceptance->reasons($invoice) === [])) {
            // Revocations and stale manual decisions need a person; never silently replace them.
            return;
        }

        $okayToPay = app(OkayToPay::class);
        if ($okayToPay->mismatches($invoice, requireAcceptance: false)) {
            return;
        }

        $evidence = [];
        $serials = [];
        foreach ($invoice->items as $line) {
            $asset = $line->item;
            if (! $asset instanceof Asset || (int) $line->quantity !== 1 || ! $this->received($asset)) {
                return;
            }
            // Hardware cannot count the same serial twice. A separate coverage line may reuse it.
            if (! $line->isSoftCostOnly()) {
                if (in_array($asset->serial, $serials, true)) {
                    return;
                }
                $serials[] = $asset->serial;
            }

            $log = Actionlog::where('item_type', Asset::class)->where('item_id', $asset->id)
                ->where('log_meta', 'like', '%"'.self::SOURCE.'"%')->latest('id')->first();
            $facts = $log ? (json_decode($log->log_meta, true)[self::SOURCE]['new'] ?? null) : null;
            $actor = $log?->adminuser;
            if ($facts !== $this->identity($asset) || ! $actor instanceof User || $actor->deleted_at || ! $actor->activated
                || ! Gate::forUser($actor)->allows('procurement.edit')
                || ((Setting::getSettings()?->full_multiple_companies_support == '1')
                    && $actor->company_id !== null && ! $actor->isSuperUser()
                    && (int) $actor->company_id !== (int) $invoice->order->company_id)) {
                return;
            }
            $evidence[$log->id] = $log;
        }
        if ($evidence === []) {
            return;
        }

        $ids = implode(',', array_keys($evidence));
        $reference = strlen($ids) <= 800 ? 'action logs '.$ids
            : count($evidence).' action logs retained in acceptance snapshot; SHA-256 '.hash('sha256', $ids);
        $actor = end($evidence)->adminuser;
        // The ordinary acceptance service supplies locking, version checks, history protection,
        // an immutable invoice snapshot and the normal queue transition. It sends no mail here.
        $acceptance->record($invoice, $actor, [
            'decision' => 'accept',
            'fingerprint' => $acceptance->fingerprint($invoice),
            'expected_acceptance_id' => $latest?->id,
            'received_in_full' => true,
            'satisfactory' => true,
            'receipt_reference' => self::SOURCE.': '.$reference,
            'acceptance_reference' => self::SOURCE.': inventoried, provisioned or active in good order; '.$reference,
        ], array_keys($evidence));
    }

    private function identity(Asset $asset): array
    {
        return [
            'serial' => (string) $asset->serial,
            'status_id' => (int) $asset->status_id,
            'company_id' => $asset->company_id,
            'lessor_id' => $asset->lessor_id,
            'lease_contract_id' => $asset->lease_contract_id,
        ];
    }
}
