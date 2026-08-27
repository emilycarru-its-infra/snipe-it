<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\OrderItem;
use App\Models\StoreOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Marry an unallocated arrival to the request that has been waiting for it.
 *
 * The webhook does this automatically when it can: a shipment claims the
 * serial-less asset the store provisioned, matched on the ECU-STORE
 * reference or the model. What is left over is exactly two pools —
 *
 *   arrivals: assets the webhook created because nothing claimable
 *             matched. Real hardware, serial in hand, nobody's yet.
 *             (Rod's example: CDW ships eight MacBook Airs, five were
 *             ordered through the store, three are extras.)
 *
 *   waiting:  assets the store provisioned that no shipment has filled.
 *             A person is watching a status page for each of these.
 *
 * Allocation moves the arrival's physical facts (serial, dates, costs,
 * custom fields) onto the waiting record, repoints the CDW order's line
 * item at it, deletes the now-empty arrival record, and flips the store
 * order to shipped — the same end state the automatic claim produces,
 * with a human choosing the pairing instead of FIFO.
 *
 * Model equality is required. "It matches all the specs I need" is the
 * whole justification for taking a unit; a different model is a different
 * machine and never allocates.
 */
class ArrivalAllocator
{
    /** Physical facts that travel from the arrival to the waiting record. */
    private const TRANSFER_FIELDS = [
        'serial', 'purchase_date', 'purchase_cost', 'warranty_months',
    ];

    /**
     * Arrivals with nobody: serial in hand, pending status, unassigned,
     * and not carrying a store reference (those are already someone's).
     *
     * @return Collection<int, Asset>
     */
    public function unallocatedArrivals(): Collection
    {
        return Asset::whereNotNull('serial')
            ->where('serial', '!=', '')
            ->whereNull('assigned_to')
            ->where(fn ($q) => $q->whereNull('order_number')
                ->orWhere('order_number', 'not like', 'ECU-STORE-%'))
            ->whereHas('status', fn ($q) => $q->where('pending', 1))
            ->with('model', 'status')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Requests still waiting on hardware: the store's provisioned assets
     * no shipment has filled, oldest order first.
     *
     * @return Collection<int, Asset>
     */
    public function waitingRequests(): Collection
    {
        return Asset::where('order_number', 'like', 'ECU-STORE-%')
            ->where(fn ($q) => $q->whereNull('serial')->orWhere('serial', ''))
            ->whereNull('assigned_to')
            ->with('model', 'status')
            ->orderBy('id')
            ->get();
    }

    /**
     * Claim a waiting request for an arrival that just landed, without
     * anybody pairing it by hand.
     *
     * The matching contract the docblocks used to describe — CDW echoing
     * our ECU-STORE reference back — never existed; CDW returns their own
     * order number and nothing of ours. What we do have by ship time is
     * that number recorded against the purchase order we raised, so the
     * arrival's order_number resolves through the paperwork instead:
     *
     *   asset.order_number  =  purchase_orders.vendor_order_number
     *                              -> requisitions
     *                              -> store_orders
     *                              -> the assets those orders provisioned
     *
     * Model equality still decides, exactly as in the manual path — a
     * batch of eight Airs against five store orders claims five and
     * leaves three unallocated rather than forcing a pairing. Oldest
     * order first, because the person who has been waiting longest is the
     * one whose status page should move.
     *
     * Returns the claimed record, or null when nothing matches — which is
     * not a failure. Extras, a purchase order nobody has stamped yet, and
     * a model we never provisioned all land on the manual allocation page,
     * which is what it is for.
     */
    /**
     * Whether an asset can be an arrival at all, judged from the row alone
     * with no further queries: a vendor order reference and a serial.
     * The observer asks this before deferring, so the thousands of assets
     * that are plainly not arrivals never queue a callback.
     */
    public function couldBeArrival(Asset $asset): bool
    {
        $reference = trim((string) $asset->order_number);

        return $reference !== '' && ! str_starts_with($reference, 'ECU-STORE-') && filled($asset->serial);
    }

    public function autoAllocate(Asset $arrival): ?Asset
    {
        if (! $this->couldBeArrival($arrival)) {
            return null;
        }

        $reference = trim((string) $arrival->order_number);

        // Only ever claims on behalf of an arrival nobody has touched:
        // assigned, or moved out of a pending status, means a human has
        // already decided what this machine is for.
        if ($arrival->assigned_to || ! $arrival->status?->pending) {
            return null;
        }

        $waiting = $this->waitingFor($reference)
            ->first(fn (Asset $candidate) => (int) $candidate->model_id === (int) $arrival->model_id);

        if (! $waiting) {
            return null;
        }

        return $this->allocate($arrival, $waiting)['asset'];
    }

    /**
     * The unfilled requests behind a vendor order number, oldest order
     * first.
     *
     * @return Collection<int, Asset>
     */
    private function waitingFor(string $vendorOrderNumber): Collection
    {
        $references = StoreOrder::query()
            ->whereHas('requisition.purchaseOrder',
                fn ($q) => $q->where('vendor_order_number', $vendorOrderNumber))
            ->orderBy('created_at')
            ->pluck('id')
            ->map(fn ($id) => 'ECU-STORE-'.$id);

        if ($references->isEmpty()) {
            return collect();
        }

        // Ordered by the reference list rather than by asset id, so "oldest
        // order first" survives a batch whose assets were provisioned out
        // of sequence.
        $byReference = Asset::whereIn('order_number', $references)
            ->where(fn ($q) => $q->whereNull('serial')->orWhere('serial', ''))
            ->whereNull('assigned_to')
            ->with('model', 'status')
            ->get()
            ->groupBy('order_number');

        return $references
            ->flatMap(fn ($reference) => $byReference->get($reference, collect()))
            ->values();
    }

    /**
     * @return array{asset: Asset, released: bool}
     */
    public function allocate(Asset $arrival, Asset $waiting): array
    {
        if (! filled($arrival->serial)) {
            throw new \InvalidArgumentException(trans('admin/orders/general.allocate_no_serial'));
        }

        if (filled($waiting->serial) || ! str_starts_with((string) $waiting->order_number, 'ECU-STORE-')) {
            throw new \InvalidArgumentException(trans('admin/orders/general.allocate_not_waiting'));
        }

        if ((int) $arrival->model_id !== (int) $waiting->model_id) {
            throw new \InvalidArgumentException(trans('admin/orders/general.allocate_model_mismatch'));
        }

        DB::transaction(function () use ($arrival, $waiting) {
            foreach (self::TRANSFER_FIELDS as $field) {
                if (filled($arrival->{$field})) {
                    $waiting->{$field} = $arrival->{$field};
                }
            }

            // Custom fields (_snipeit_*) carry the PO, invoice, tracking and
            // friends that the webhook stamped on the arrival — physical
            // facts, so they travel too.
            foreach ($arrival->getAttributes() as $key => $value) {
                if (str_starts_with($key, '_snipeit_') && filled($value)) {
                    $waiting->{$key} = $value;
                }
            }

            $waiting->notes = trim(($waiting->notes ? $waiting->notes."\n" : '')
                .trans('admin/orders/general.allocate_note', [
                    'tag' => $arrival->asset_tag,
                    'order' => $arrival->order_number ?: '—',
                ]));
            $waiting->saveQuietly();

            // The CDW order's line item now points at the record that kept
            // the person's name and A-number, so the order page shows the
            // allocated machine rather than a dangling reference.
            OrderItem::where('item_type', Asset::class)
                ->where('item_id', $arrival->id)
                ->update(['item_id' => $waiting->id]);

            // The arrival record's serial must vacate before it is deleted,
            // or a future webhook byserial lookup finds a soft-deleted twin.
            $arrival->serial = null;
            $arrival->saveQuietly();
            $arrival->delete();
        });

        $this->notifyStoreOrder($waiting);

        return ['asset' => $waiting->fresh('model', 'status'), 'released' => true];
    }

    /**
     * The allocation is the shipment landing, from the requester's point
     * of view — so their order flips to shipped and they get the email,
     * same as when the webhook claims automatically.
     */
    private function notifyStoreOrder(Asset $waiting): void
    {
        if (! preg_match('/^ECU-STORE-(\d+)$/', (string) $waiting->order_number, $m)) {
            return;
        }

        $order = StoreOrder::find((int) $m[1]);

        if (! $order || ! in_array($order->status, ['approved', 'ordered'], true)) {
            return;
        }

        $order->update([
            'status' => 'ordered',
            'shipped_at' => $order->shipped_at ?? now(),
        ]);

        StoreOrderNotifier::requester($order->load('items', 'user'), 'shipped', [
            'serials' => [$waiting->serial],
        ]);
    }
}
