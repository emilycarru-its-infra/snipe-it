<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The moment an order is placed, its devices become real assets.
 *
 * This is the 2026-07-29 process change carried to its conclusion. An order
 * is no longer a promise that an asset will appear when CDW's webhook lands
 * an invoice — each device unit is created in Snipe at submission, with an
 * A-number, its model, the requester's name, and the order reference in the
 * native order_number field. What it does not have yet is a serial, because
 * the hardware does not exist yet; the CDW webhook fills that in at ship
 * time by claiming these very records (matched on order_number + model)
 * instead of creating parallel ones.
 *
 * Why at submission rather than approval: the requester's status page shows
 * "your new machine" with a real asset tag from the first minute, procurement
 * sees real records in the queue, and a declined or cancelled order releases
 * its assets again — which is cheap, because an unclaimed asset has no
 * serial, no history and no assignment.
 *
 * The reference is the whole matching contract:
 *
 *   assets.order_number = "ECU-STORE-<order id>"
 *
 * The same string goes to CDW on the order request and comes back on their
 * confirmation, so the webhook, the status page and the vendor email all
 * speak about the same order the same way.
 */
class StoreOrderAssetProvisioner
{
    /** Status a pre-created asset waits in until its serial arrives. */
    public const ORDERED_STATUS = 'New (Ordered)';

    /** Suffix the outgoing machine carries once its replacement is ordered. */
    public const LEASE_END_SUFFIX = '-LE';

    /**
     * Create one asset per device unit on the order. Accessory lines are
     * skipped — cables and pencils are not asset-tracked, which is the same
     * rule the store's shelf applies.
     *
     * @return Collection<int, Asset>
     */
    public function provision(StoreOrder $order): Collection
    {
        $created = collect();
        $status = $this->orderedStatus();

        foreach ($order->items as $line) {
            $modelId = $line->catalogItem?->model_id;

            if (! $modelId) {
                continue;
            }

            for ($unit = 0; $unit < (int) $line->quantity; $unit++) {
                $asset = $this->provisionUnit($order, $line, $modelId, $status->id);

                if ($asset) {
                    $created->push($asset);
                }
            }
        }

        if ($order->isFacultyProgram()) {
            $this->markOutgoingMachine($order);
        }

        return $created;
    }

    /**
     * The assets this order created, serials filled or not — what the
     * status page and the queue show as "your new machine".
     *
     * @return Collection<int, Asset>
     */
    public function assetsFor(StoreOrder $order): Collection
    {
        return Asset::where('order_number', $order->reference())
            ->with('model', 'status')
            ->orderBy('id')
            ->get();
    }

    /**
     * Release the assets of an order that will never arrive. Only records
     * the webhook has not touched are deleted: no serial, still in the
     * ordered status, never assigned. Anything past that point is real
     * hardware and stays.
     */
    public function release(StoreOrder $order): int
    {
        $releasable = Asset::where('order_number', $order->reference())
            ->whereNull('assigned_to')
            ->where(fn ($q) => $q->whereNull('serial')->orWhere('serial', ''))
            ->get()
            ->filter(fn (Asset $asset) => $asset->status?->name === self::ORDERED_STATUS);

        foreach ($releasable as $asset) {
            $asset->delete();
        }

        return $releasable->count();
    }

    private function provisionUnit(StoreOrder $order, StoreOrderItem $line, int $modelId, int $statusId): ?Asset
    {
        $asset = new Asset;
        $asset->model_id = $modelId;
        $asset->status_id = $statusId;
        $asset->asset_tag = Asset::autoincrement_asset() ?: $this->fallbackTag($order);

        // A single-unit line is one person's machine and carries their name,
        // which is how the fleet is named here. A multi-unit line is stock,
        // and stock is nameless until it is handed to someone.
        if ((int) $line->quantity === 1) {
            $asset->name = $order->user?->present()->fullName;
        }

        $asset->order_number = $order->reference();
        $asset->purchase_cost = (float) $line->unit_cost ?: null;
        $asset->supplier_id = $line->catalogItem?->supplier_id;
        $asset->notes = trans('admin/store/general.asset_provisioned_note', [
            'reference' => $order->reference(),
            'name' => $order->user?->present()->fullName ?? '#'.$order->user_id,
        ]);

        if (! $asset->save()) {
            Log::error('Store order '.$order->id.' could not provision an asset: '
                .json_encode($asset->getErrors()->all()));

            return null;
        }

        return $asset;
    }

    /**
     * Rename the machine this order replaces. Other automations stamp the
     * lease-end suffix when the contract turns; this is the backstop for
     * the case where the order arrives first, so the queue and the status
     * page never show two unmarked machines against one person.
     */
    private function markOutgoingMachine(StoreOrder $order): void
    {
        $outgoing = $this->outgoingMachine($order);

        if (! $outgoing || str_contains((string) $outgoing->name, self::LEASE_END_SUFFIX)) {
            return;
        }

        $outgoing->name = trim((string) $outgoing->name).' '.self::LEASE_END_SUFFIX;
        $outgoing->saveQuietly();
    }

    /**
     * The requester's current machine — same resolution the faculty intake
     * form uses, so the two surfaces never disagree about which laptop is
     * "the old one".
     */
    public function outgoingMachine(StoreOrder $order): ?Asset
    {
        return Asset::currentLaptopOf($order->user_id);
    }

    /**
     * Resolved by name rather than a hardcoded id, because ids differ
     * between environments and test databases; created when absent so a
     * fresh environment works without seeding.
     */
    private function orderedStatus(): Statuslabel
    {
        return Statuslabel::firstOrCreate(
            ['name' => self::ORDERED_STATUS],
            ['notes' => 'Ordered from the supplier; serial arrives with the shipment.', 'pending' => 1,
                'archived' => 0, 'deployable' => 0]
        );
    }

    /**
     * Only reached when tag auto-increment is off — which it is not, on any
     * environment we run — but a store order must not fail because a
     * setting was toggled.
     */
    private function fallbackTag(StoreOrder $order): string
    {
        return $order->reference().'-'.strtoupper(substr(uniqid(), -6));
    }
}
