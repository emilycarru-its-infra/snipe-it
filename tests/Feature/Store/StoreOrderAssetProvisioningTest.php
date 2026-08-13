<?php

namespace Tests\Feature\Store;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Manufacturer;
use App\Models\Group;
use App\Models\Setting;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StoreOrderAssetProvisioner;
use Tests\TestCase;

/**
 * An order's devices become real assets the moment it is placed.
 *
 * The contract under test: one asset per device unit, A-tag from the
 * auto-incrementer, the requester's name on single-unit lines, the order
 * reference in order_number (which is what the CDW webhook later matches
 * on), no serial until the shipment brings one — and the whole thing
 * reversible while, and only while, nothing has shipped.
 */
class StoreOrderAssetProvisioningTest extends TestCase
{
    private function enableAutoTags(): void
    {
        $settings = Setting::getSettings();
        $settings->auto_increment_assets = 1;
        $settings->auto_increment_prefix = 'A';
        $settings->zerofill_count = 6;
        $settings->next_auto_tag_base = 900001;
        $settings->save();
    }

    private function laptopModel(): AssetModel
    {
        return AssetModel::factory()->create([
            'category_id' => Category::factory()->create(['name' => 'Laptop'])->id,
        ]);
    }

    private function requester(): User
    {
        $group = Group::factory()->create(['name' => 'Regular Faculty', 'permissions' => json_encode([])]);
        $user = User::factory()->create(['activated' => 1, 'first_name' => 'Frida', 'last_name' => 'Kahlo']);
        $user->groups()->attach($group->id);

        return $user;
    }

    private function shelfItem(array $overrides = []): CatalogItem
    {
        if (! array_key_exists('model_id', $overrides)) {
            $overrides['model_id'] = AssetModel::factory()->create()->getKey();
        }

        return CatalogItem::create(array_merge([
            'name' => 'MacBook Pro | 14" | M5 | 16GB | 1TB',
            'family' => 'MacBook Pro',
            'category' => 'Laptops',
            'product_type' => 'standard',
            'vendor_sku' => (string) random_int(1000000, 9999999),
            'unit_cost' => 2658.77,
            'price_type' => 'quoted',
            'show_in_store' => true,
        ], $overrides));
    }

    public function test_placing_an_order_creates_one_asset_per_device_unit()
    {
        $this->enableAutoTags();
        $supplier = Supplier::create(['name' => 'CDW Canada Inc']);
        $laptop = $this->shelfItem(['supplier_id' => $supplier->id]);
        $cable = $this->shelfItem(['name' => 'TB5 Cable', 'category' => 'Accessories', 'model_id' => null]);
        $user = $this->requester();

        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [
                ['catalog_item_id' => $laptop->id, 'quantity' => 1],
                ['catalog_item_id' => $cable->id, 'quantity' => 3],
            ],
        ])->assertRedirect(route('store.orders'));

        $order = StoreOrder::first();
        $assets = Asset::where('order_number', $order->reference())->get();

        // One asset for the laptop; the cable is not asset-tracked.
        $this->assertCount(1, $assets);

        $asset = $assets->first();
        $this->assertSame('A900001', $asset->asset_tag);
        $this->assertSame($laptop->model_id, (int) $asset->model_id);
        $this->assertSame('Frida Kahlo', $asset->name);
        $this->assertSame(StoreOrderAssetProvisioner::ORDERED_STATUS, $asset->status->name);
        $this->assertEmpty($asset->serial);
        $this->assertNull($asset->assigned_to);
        $this->assertSame($supplier->id, (int) $asset->supplier_id);
        $this->assertEqualsWithDelta(2658.77, (float) $asset->purchase_cost, 0.01);
    }

    public function test_multi_unit_lines_get_one_asset_each_and_no_personal_name()
    {
        $this->enableAutoTags();
        $laptop = $this->shelfItem();

        $this->actingAs($this->requester())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $laptop->id, 'quantity' => 3]],
        ]);

        $assets = Asset::where('order_number', StoreOrder::first()->reference())->get();

        $this->assertCount(3, $assets);
        $this->assertSame(['A900001', 'A900002', 'A900003'], $assets->pluck('asset_tag')->sort()->values()->all());

        // Stock is nameless until it is handed to someone.
        $assets->each(fn (Asset $asset) => $this->assertEmpty($asset->name));
    }

    public function test_a_faculty_refresh_marks_the_outgoing_machine()
    {
        $this->enableAutoTags();
        $user = $this->requester();

        $old = Asset::factory()->create([
            'name' => 'Frida Kahlo',
            'model_id' => $this->laptopModel()->id,
            'assigned_to' => $user->id,
            'assigned_type' => User::class,
            'last_checkout' => now()->subYears(3),
        ]);

        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $this->shelfItem()->id, 'quantity' => 1]],
        ]);

        // The group name contains "faculty", so the order rides the program
        // and the machine it replaces is marked as leaving.
        $this->assertSame('faculty', StoreOrder::first()->program);
        $this->assertSame('Frida Kahlo -LE', $old->fresh()->name);

        // Idempotent: a second order does not stack suffixes.
        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $this->shelfItem(['vendor_sku' => '999'])->id, 'quantity' => 1]],
        ]);
        $this->assertSame('Frida Kahlo -LE', $old->fresh()->name);
    }

    public function test_cancelling_and_declining_release_the_unshipped_assets()
    {
        $this->enableAutoTags();
        $user = $this->requester();
        $laptop = $this->shelfItem();

        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $laptop->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();
        $this->assertCount(1, Asset::where('order_number', $order->reference())->get());

        $this->actingAs($user)->post(route('store.orders.cancel', $order->id));
        $this->assertCount(0, Asset::where('order_number', $order->reference())->get());

        // Decline path, same release.
        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $laptop->id, 'quantity' => 1]],
        ]);
        $second = StoreOrder::orderByDesc('id')->first();

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('procurement.queue.decide', $second->id), ['decision' => 'declined']);
        $this->assertCount(0, Asset::where('order_number', $second->reference())->get());
    }

    public function test_an_asset_the_webhook_already_claimed_is_never_released()
    {
        $this->enableAutoTags();
        $user = $this->requester();

        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $this->shelfItem()->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();

        // The shipment landed a serial: this is real hardware now.
        Asset::where('order_number', $order->reference())->first()->update(['serial' => 'C02XYZ123']);

        $this->actingAs($user)->post(route('store.orders.cancel', $order->id));

        $this->assertCount(1, Asset::where('order_number', $order->reference())->get());
    }

    public function test_the_status_page_shows_both_sides_of_the_handover()
    {
        $this->enableAutoTags();
        $user = $this->requester();

        Asset::factory()->create([
            'name' => 'Frida Kahlo',
            'asset_tag' => 'A000042',
            'model_id' => $this->laptopModel()->id,
            'assigned_to' => $user->id,
            'assigned_type' => User::class,
            'last_checkout' => now()->subYears(3),
        ]);

        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $this->shelfItem()->id, 'quantity' => 1]],
        ]);

        $page = $this->actingAs($user)->get(route('store.orders'))->assertOk();

        // The handover reads left to right: the old machine, marked as
        // leaving, and the new one with its tag already assigned.
        $page->assertSee('Your current laptop', false);
        $page->assertSee('A000042', false);
        $page->assertSee('Frida Kahlo -LE', false);
        $page->assertSee('Your new laptop', false);
        $page->assertSee('A900001', false);
        $page->assertSee(StoreOrder::first()->reference(), false);
        $page->assertSee('Requested', false);
        $page->assertSee('Arrived', false);
    }

    public function test_a_first_machine_says_so_instead_of_showing_an_old_one()
    {
        $this->enableAutoTags();
        $user = $this->requester();

        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $this->shelfItem()->id, 'quantity' => 1]],
        ]);

        $this->actingAs($user)->get(route('store.orders'))
            ->assertOk()
            ->assertSee('No current laptop', false)
            ->assertSee('Your new laptop', false);
    }

    public function test_provisioning_survives_auto_tags_being_off()
    {
        // No auto-increment configured: the order must still place, with a
        // reference-derived tag rather than a failure.
        $user = $this->requester();

        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $this->shelfItem()->id, 'quantity' => 1]],
        ])->assertRedirect(route('store.orders'));

        $order = StoreOrder::first();
        $asset = Asset::where('order_number', $order->reference())->first();

        $this->assertNotNull($asset);
        $this->assertStringStartsWith($order->reference(), $asset->asset_tag);
    }

    /**
     * Phil's two iPads. The model carried the "Devices" fieldset, whose
     * Platform and Catalog fields are required; the provisioner set
     * neither, validation rejected every unit, and StoreController caught
     * the failure so the order still looked placed. Two devices went
     * missing and nobody noticed for a day.
     *
     * 50 of the 61 items on the shelf are on a fieldset — the laptops that
     * did provision only worked because that one model happens to carry
     * none — so this was never about iPads.
     */
    public function test_a_model_with_required_custom_fields_still_provisions(): void
    {
        $this->enableAutoTags();

        $platform = CustomField::factory()->create([
            'name' => 'Platform', 'element' => 'listbox',
            'field_values' => "Macintosh\r\nWindows\r\niPadOS",
        ]);
        $catalog = CustomField::factory()->create([
            'name' => 'Catalog', 'element' => 'listbox',
            'field_values' => "Curriculum\r\nStaff\r\nFaculty",
        ]);
        $fieldset = CustomFieldset::factory()->create(['name' => 'Devices']);
        $fieldset->fields()->attach($platform->id, ['required' => true, 'order' => 1]);
        $fieldset->fields()->attach($catalog->id, ['required' => true, 'order' => 2]);

        $apple = Manufacturer::factory()->create(['name' => 'Apple']);
        $model = AssetModel::factory()->create([
            'category_id' => Category::factory()->create(['name' => 'Tablets', 'category_type' => 'asset'])->id,
            'manufacturer_id' => $apple->id,
            'fieldset_id' => $fieldset->id,
        ]);
        $item = $this->shelfItem([
            'name' => 'iPad |11" l 128GB | Silver', 'category' => 'Tablets',
            'model_id' => $model->id, 'unit_cost' => 600,
        ]);

        $user = $this->requester();
        $this->actingAs($user)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 2]],
        ])->assertRedirect(route('store.orders'));

        $order = StoreOrder::where('user_id', $user->id)->latest('id')->first();
        $assets = Asset::where('order_number', $order->reference())->get();

        $this->assertCount(2, $assets, 'both iPads should exist');
        $this->assertSame(0, $order->fresh()->load('items.catalogItem')->provisioningShortfall());

        // And the required fields carry answers the store actually knows,
        // not filler to get past a validator.
        $first = $assets->first();
        $this->assertSame('iPadOS', $first->{$platform->db_column});
        // requester() is in Regular Faculty, so this is a programme order.
        $this->assertSame('Faculty', $first->{$catalog->db_column});
    }

    /** A faculty programme order is a Faculty machine. */
    public function test_a_faculty_order_stamps_the_faculty_catalog(): void
    {
        $this->enableAutoTags();

        $catalog = CustomField::factory()->create([
            'name' => 'Catalog', 'element' => 'listbox',
            'field_values' => "Curriculum\r\nStaff\r\nFaculty",
        ]);
        $fieldset = CustomFieldset::factory()->create(['name' => 'Devices']);
        $fieldset->fields()->attach($catalog->id, ['required' => true, 'order' => 1]);

        $model = AssetModel::factory()->create([
            'category_id' => Category::firstOrCreate(
                ['name' => 'Laptop', 'category_type' => 'asset'], ['created_by' => 1])->id,
            'manufacturer_id' => Manufacturer::factory()->create(['name' => 'Apple'])->id,
            'fieldset_id' => $fieldset->id,
        ]);
        $item = $this->shelfItem(['model_id' => $model->id, 'unit_cost' => 2100]);

        $faculty = $this->requester();

        $this->actingAs($faculty)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect(route('store.orders'));

        $order = StoreOrder::where('user_id', $faculty->id)->latest('id')->first();
        $asset = Asset::where('order_number', $order->reference())->first();

        $this->assertNotNull($asset);
        $this->assertSame('Faculty', $asset->{$catalog->db_column});
    }
}
