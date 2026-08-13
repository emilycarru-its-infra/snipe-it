<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\UserAgreement;
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
        // and stock is nameless until it is handed to someone. A shared
        // order is never anyone's machine: no name, and the asset carries
        // the Shared usage tag from birth instead of waiting for the
        // location-assignment automations to stamp it.
        if ($order->isShared()) {
            $asset->lease_usage = 'Shared';
            // The space the order was placed for is the machine's home,
            // set now so the device is findable in the room before anyone
            // has touched it. rtd_location_id is the default location
            // Snipe returns an asset to on check-in.
            $asset->rtd_location_id = $order->location_id;
            $asset->location_id = $order->location_id;
        } else {
            // An assigned order is going to a person, and where that person
            // sits is where the device is headed — so it gets their location
            // rather than none at all. Provisioned assets used to arrive
            // with no location whatsoever, which is what the Staff Devices
            // Missing Location alert was reporting: a device nobody could
            // place, weeks before it turns up.
            $asset->rtd_location_id = $order->user?->location_id;
            $asset->location_id = $order->user?->location_id;

            if ((int) $line->quantity === 1) {
                $asset->name = $order->user?->present()->fullName;
            }
        }

        $this->stampRequiredCustomFields($asset, $order, $line);

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
     * Fill the custom fields the model's fieldset insists on.
     *
     * Without this an order for anything whose model carries a fieldset
     * provisioned nothing at all: validation rejected the save, the method
     * below logged and returned null, and StoreController caught it so the
     * order still succeeded. Two iPads went missing that way and nobody
     * noticed until somebody went looking for the order — 50 of the 61
     * items on the shelf are on a fieldset, so the six laptops that did
     * provision only worked because that one model happens to carry none.
     *
     * Resolved by field name rather than by the `_snipeit_*` column, which
     * is an environment fact: the same field is a different column in dev.
     * Only fields we can actually answer are set, and only when required —
     * guessing at Memory or Colour to satisfy a validator would put
     * invented facts on an asset record.
     */
    private function stampRequiredCustomFields(Asset $asset, StoreOrder $order, StoreOrderItem $line): void
    {
        $fields = $asset->model?->fieldset?->fields;

        if (! $fields) {
            return;
        }

        $known = [
            'Platform' => $this->platformFor($line),
            'Catalog' => $this->catalogFor($order),
        ];

        foreach ($fields as $field) {
            $value = $known[$field->name] ?? null;

            // `required` is a property of this field *on this fieldset*, so
            // it lives on the pivot — the same field is optional elsewhere.
            if ($value !== null && $field->pivot?->required && $field->db_column) {
                $asset->{$field->db_column} = $value;
            }
        }
    }

    /**
     * Which platform this line is, in the Platform field's own vocabulary.
     *
     * CatalogItem::platform() answers Macintosh for everything Apple makes,
     * which is right for the store's filtering and wrong on an iPad's asset
     * record. The category is what separates them.
     */
    private function platformFor(StoreOrderItem $line): ?string
    {
        $item = $line->catalogItem;
        $manufacturer = $item?->model?->manufacturer?->name;

        if (! $manufacturer) {
            return null;
        }

        if ($manufacturer !== 'Apple') {
            return 'Windows';
        }

        $name = strtolower(trim(($item->category ?? '').' '.($item->name ?? '')));

        return match (true) {
            str_contains($name, 'ipad') => 'iPadOS',
            str_contains($name, 'iphone') => 'iOS',
            str_contains($name, 'apple tv') => 'tvOS',
            str_contains($name, 'vision') => 'visionOS',
            default => 'Macintosh',
        };
    }

    /**
     * Which programme the device belongs to. The order already knows: a
     * faculty-programme order is a Faculty machine, a shared cart buys for
     * a room and those are Curriculum, and everything else is Staff.
     */
    private function catalogFor(StoreOrder $order): string
    {
        return match (true) {
            $order->isFacultyProgram() => 'Faculty',
            $order->isShared() => 'Curriculum',
            default => 'Staff',
        };
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
     * The machine this order replaces. The intake form's own pick wins —
     * whoever holds several laptops said on the form which one they are
     * returning, and that answer is stored on their open pickup agreement.
     * Only when no agreement carries a pick does the resolver fall back to
     * guessing their most recent laptop.
     */
    public function outgoingMachine(StoreOrder $order): ?Asset
    {
        $picked = UserAgreement::where('user_id', $order->user_id)
            ->where('agreement_type', 'pickup')
            ->whereIn('lifecycle_stage', UserAgreement::OPEN_LIFECYCLE_STAGES)
            ->whereNotNull('asset_id')
            ->latest('created_at')
            ->first()?->asset;

        return $picked ?? Asset::currentLaptopOf($order->user_id);
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
