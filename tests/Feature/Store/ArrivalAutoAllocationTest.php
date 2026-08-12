<?php

namespace Tests\Feature\Store;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\Statuslabel;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\ArrivalAllocator;
use App\Services\StoreOrderAssetProvisioner;
use Tests\TestCase;

/**
 * An arriving machine finds the person waiting for it on its own.
 *
 * The matching contract both provisioner and allocator used to describe —
 * CDW echoing our ECU-STORE reference back — never existed. CDW returns
 * their own order number, so the arrival resolves through the paperwork
 * instead: order_number → purchase_orders.vendor_order_number →
 * requisitions → store orders → the assets those orders provisioned.
 */
class ArrivalAutoAllocationTest extends TestCase
{
    private const VENDOR_ORDER = 'PZGK281';

    /**
     * The observer defers its allocation so that deleting the emptied
     * arrival cannot happen while the request that created it is still
     * holding the row. Running deferred functions inline is what lets a
     * test observe the finished state — so these assert the whole path,
     * from "an asset was created" through to "the person waiting for it
     * has its serial", rather than calling the allocator by hand.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
    }

    private function laptopModel(string $name = 'MacBook Air 13 M5'): AssetModel
    {
        // One shared category: a second Category named "Laptop" trips the
        // unique constraint, and a failed save leaves an unsaved model
        // behind rather than an error, which is a confusing way to fail.
        $category = Category::firstOrCreate(
            ['name' => 'Laptop', 'category_type' => 'asset'],
            ['created_by' => 1],
        );

        return AssetModel::factory()->create(['name' => $name, 'category_id' => $category->id]);
    }

    private function pendingStatus(): Statuslabel
    {
        return Statuslabel::firstOrCreate(
            ['name' => StoreOrderAssetProvisioner::ORDERED_STATUS],
            ['deployable' => 0, 'pending' => 1, 'archived' => 0],
        );
    }

    /** A store order wired to a purchase order carrying CDW's number. */
    private function storeOrderAwaiting(AssetModel $model, string $vendorOrderNumber = self::VENDOR_ORDER): array
    {
        $po = PurchaseOrder::factory()->create(['vendor_order_number' => $vendorOrderNumber]);
        $requisition = Requisition::create([
            'requisition_number' => 'REQ-'.$po->id,
            'title' => 'Faculty laptops',
            'status' => 'ordered',
            'purchase_order_id' => $po->id,
        ]);

        $user = User::factory()->create();
        $order = StoreOrder::create([
            'user_id' => $user->id,
            'status' => 'ordered',
            'requisition_id' => $requisition->id,
        ]);

        $waiting = Asset::factory()->create([
            'model_id' => $model->id,
            'status_id' => $this->pendingStatus()->id,
            'order_number' => $order->reference(),
            'serial' => null,
            'assigned_to' => null,
            'name' => $user->present()->fullName,
        ]);

        return [$order, $waiting];
    }

    private function arrival(AssetModel $model, string $serial, string $vendorOrderNumber = self::VENDOR_ORDER): Asset
    {
        return Asset::factory()->create([
            'model_id' => $model->id,
            'status_id' => $this->pendingStatus()->id,
            'order_number' => $vendorOrderNumber,
            'serial' => $serial,
            'assigned_to' => null,
        ]);
    }

    public function test_an_arrival_claims_the_request_waiting_for_it(): void
    {
        $model = $this->laptopModel();
        [$order, $waiting] = $this->storeOrderAwaiting($model);
        $arrival = $this->arrival($model, 'SERIAL0001');

        // The requester keeps their A-number and name; only the physical
        // facts move across.
        $claimed = $waiting->fresh();
        $this->assertSame('SERIAL0001', $claimed->serial);
        $this->assertSame($order->reference(), $claimed->order_number);
        $this->assertEquals($waiting->asset_tag, $claimed->asset_tag);
        $this->assertNull(Asset::find($arrival->id), 'the emptied arrival record should be gone');
    }

    public function test_a_different_model_never_allocates(): void
    {
        $wanted = $this->laptopModel('MacBook Air 13 M5');
        [, $waiting] = $this->storeOrderAwaiting($wanted);
        $arrival = $this->arrival($this->laptopModel('MacBook Pro 14 M5'), 'SERIAL0002');

        $this->assertNull($waiting->fresh()->serial);
        $this->assertNotNull(Asset::find($arrival->id));
    }

    /**
     * Rod's case: CDW ships more than was ordered through the store. The
     * extras must stay unallocated rather than being forced onto somebody.
     */
    public function test_extras_on_a_batch_are_left_unallocated(): void
    {
        $model = $this->laptopModel();
        [, $first] = $this->storeOrderAwaiting($model);
        [, $second] = $this->storeOrderAwaiting($model);

        $this->arrival($model, 'SERIAL0003');
        $this->arrival($model, 'SERIAL0004');
        $extra = $this->arrival($model, 'SERIAL0005');

        $this->assertSame('SERIAL0003', $first->fresh()->serial, 'oldest order first');
        $this->assertSame('SERIAL0004', $second->fresh()->serial);
        $this->assertNotNull(Asset::find($extra->id), 'the extra stays for manual allocation');
        $this->assertSame('SERIAL0005', $extra->fresh()->serial, 'and keeps its own serial');
    }

    public function test_an_arrival_for_an_unknown_vendor_order_is_left_alone(): void
    {
        $model = $this->laptopModel();
        $this->storeOrderAwaiting($model);
        $arrival = $this->arrival($model, 'SERIAL0006', 'NOTOURORDER');

        $this->assertNotNull(Asset::find($arrival->id));
    }

    /** A machine somebody has already decided about is not up for grabs. */
    public function test_an_assigned_arrival_is_never_claimed(): void
    {
        $model = $this->laptopModel();
        $this->storeOrderAwaiting($model);
        $arrival = $this->arrival($model, 'SERIAL0007');
        $arrival->assigned_to = User::factory()->create()->id;
        $arrival->assigned_type = User::class;
        $arrival->saveQuietly();

        $this->assertNull(app(ArrivalAllocator::class)->autoAllocate($arrival->fresh('model', 'status')));
    }

    /** A store-provisioned placeholder is a request, not an arrival. */
    public function test_a_waiting_placeholder_is_not_treated_as_an_arrival(): void
    {
        $model = $this->laptopModel();
        [, $waiting] = $this->storeOrderAwaiting($model);
        $waiting->serial = 'SERIAL0008';
        $waiting->saveQuietly();

        $this->assertNull(app(ArrivalAllocator::class)->autoAllocate($waiting->fresh('model', 'status')));
    }
}
