<?php

namespace Tests\Feature\Store;

use App\Mail\StoreOrderStatusMail;
use App\Mail\StoreVendorOrderMail;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\Group;
use App\Models\Location;
use App\Models\Requisition;
use App\Models\StoreApprover;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\VendorOrderCsv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The store funnel end to end: browse → order → queue → approve → pull
 * into a requisition — and the boundaries between the two sides. The
 * storefront is for everyone; the queue is procurement's.
 */
class StoreFunnelTest extends TestCase
{
    /** A user with no procurement permissions at all. */
    private function endUser(): User
    {
        return User::factory()->create();
    }

    private function procurement(): User
    {
        return User::factory()->superuser()->create();
    }

    private function shelfItem(array $overrides = []): CatalogItem
    {
        // Every store item resolves to a real asset model — the store
        // hides anything unlinked. An explicit null override stays null.
        if (! array_key_exists('model_id', $overrides)) {
            $overrides['model_id'] = AssetModel::factory()->create()->getKey();
        }

        return CatalogItem::create(array_merge([
            'name' => 'MacBook Pro | 14" | M5 | 16GB | 1TB',
            'family' => 'MacBook Pro',
            'category' => 'Laptops',
            'product_type' => 'standard',
            'vendor_sku' => '854413',
            'unit_cost' => 2658.77,
            'price_type' => 'quoted',
            'show_in_store' => true,
        ], $overrides));
    }

    public function test_any_user_can_browse_and_only_shelf_items_show()
    {
        // Quote-free names: the payload is JSON, where a double quote
        // leaves as ".
        $shown = $this->shelfItem(['name' => 'MacBook Pro 14 M5', 'family' => 'MacBook Pro']);
        $hidden = $this->shelfItem(['name' => 'Not on the shelf', 'vendor_sku' => '999', 'show_in_store' => false]);

        $response = $this->actingAs($this->endUser())->get(route('store.index'))->assertOk();

        $response->assertSee('MacBook Pro 14 M5', false);
        $response->assertDontSee('Not on the shelf', false);
    }

    public function test_an_item_with_no_asset_model_stays_off_the_shelf()
    {
        $unlinked = $this->shelfItem(['name' => 'Mystery Machine', 'vendor_sku' => '111', 'model_id' => null]);

        $this->actingAs($this->endUser())->get(route('store.index'))
            ->assertOk()
            ->assertDontSee('Mystery Machine', false);

        $this->actingAs($this->endUser())
            ->post(route('store.orders.store'), [
                'items' => [['catalog_item_id' => $unlinked->id, 'quantity' => 1]],
            ])
            ->assertRedirect(route('store.index'));

        $this->assertSame(0, StoreOrder::count());
    }

    public function test_accessories_render_without_needing_an_asset_model()
    {
        Mail::fake();

        // Cables and pencils are not asset-tracked, so the model
        // requirement that gates devices does not apply to them.
        $cable = $this->shelfItem(['name' => 'Thunderbolt 5 Pro Cable 1m', 'vendor_sku' => '222',
            'category' => 'Accessories', 'model_id' => null]);

        $this->actingAs($this->endUser())->get(route('store.index'))
            ->assertOk()
            ->assertSee('Thunderbolt 5 Pro Cable 1m', false);

        // And they can be ordered like anything else.
        $this->actingAs($this->endUser())
            ->post(route('store.orders.store'), [
                'items' => [['catalog_item_id' => $cable->id, 'quantity' => 2]],
            ])
            ->assertRedirect(route('store.orders'));

        $this->assertSame(1, StoreOrder::count());
    }

    public function test_the_old_builder_path_redirects_to_purchase_orders()
    {
        // Two hops now: the elevated module first (query preserved), then
        // the builder's own move to /purchase-orders.
        $this->actingAs($this->procurement())
            ->get('/reports/procurement/po-builder?requisition=7')
            ->assertRedirect('/procurement/po-builder?requisition=7');
        $this->actingAs($this->procurement())
            ->get('/procurement/po-builder?requisition=7')
            ->assertRedirect(route('purchase-orders.builder', ['requisition' => 7]));
    }

    public function test_placing_an_order_snapshots_the_catalog_price()
    {
        $item = $this->shelfItem();
        $user = $this->endUser();

        $this->actingAs($user)
            ->post(route('store.orders.store'), [
                'notes' => 'For the animation lab',
                'items' => [['catalog_item_id' => $item->id, 'quantity' => 3]],
            ])
            ->assertRedirect(route('store.orders'));

        $order = StoreOrder::first();

        $this->assertSame($user->id, (int) $order->user_id);
        $this->assertSame('pending', $order->status);
        $this->assertEqualsWithDelta(7976.31, $order->total(), 0.001);

        // The catalog moves on; the order must not.
        $item->update(['unit_cost' => 9999]);
        $this->assertEqualsWithDelta(7976.31, $order->fresh()->total(), 0.001);
    }

    public function test_the_client_cannot_set_its_own_price()
    {
        $item = $this->shelfItem();

        $this->actingAs($this->endUser())
            ->post(route('store.orders.store'), [
                'items' => [['catalog_item_id' => $item->id, 'quantity' => 1, 'unit_cost' => 0.01]],
            ])
            ->assertRedirect();

        $this->assertEqualsWithDelta(2658.77, (float) StoreOrder::first()->items->first()->unit_cost, 0.001);
    }

    public function test_an_item_hidden_from_the_store_cannot_be_ordered()
    {
        $hidden = $this->shelfItem(['show_in_store' => false]);

        $this->actingAs($this->endUser())
            ->post(route('store.orders.store'), [
                'items' => [['catalog_item_id' => $hidden->id, 'quantity' => 1]],
            ])
            ->assertRedirect(route('store.index'));

        $this->assertSame(0, StoreOrder::count());
    }

    public function test_users_see_only_their_own_orders_and_can_cancel_only_pending_ones()
    {
        $item = $this->shelfItem();
        $alice = $this->endUser();
        $bob = $this->endUser();

        $this->actingAs($alice)->post(route('store.orders.store'), [
            'notes' => 'Alice needs a laptop',
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();

        // Bob neither sees Alice's order nor can he cancel it.
        $this->actingAs($bob)->get(route('store.orders'))->assertOk()->assertDontSee('Alice needs a laptop', false);
        $this->actingAs($bob)->post(route('store.orders.cancel', $order->id))->assertForbidden();

        // Alice can — while it is still pending.
        $this->actingAs($alice)->post(route('store.orders.cancel', $order->id))->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_the_procurement_side_is_gated()
    {
        $user = $this->endUser();

        $this->actingAs($user)->followingRedirects()->get(route('procurement.index'))->assertForbidden();
        $this->actingAs($user)->get(route('procurement.approvals'))->assertForbidden();
        $this->actingAs($user)->get(route('procurement.store-admin'))->assertForbidden();
        $this->actingAs($user)->post(route('procurement.queue.pull'), ['title' => 'x', 'orders' => [1]])->assertForbidden();
    }

    public function test_approve_and_pull_lands_the_order_on_a_requisition()
    {
        $item = $this->shelfItem();
        $requester = $this->endUser();
        $staff = $this->procurement();

        $this->actingAs($requester)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 2]],
        ]);
        $order = StoreOrder::first();

        $this->actingAs($staff)
            ->post(route('procurement.queue.decide', $order->id), ['decision' => 'approved', 'decision_notes' => 'Budget fits.'])
            ->assertRedirect();

        $this->assertSame('approved', $order->fresh()->status);

        $this->actingAs($staff)
            ->post(route('procurement.queue.pull'), ['title' => 'Store orders — week 31', 'orders' => [$order->id]])
            ->assertRedirect();

        $order->refresh();
        $requisition = Requisition::with('items')->first();

        $this->assertSame('ordered', $order->status);
        $this->assertSame($requisition->id, (int) $order->requisition_id);
        $this->assertCount(1, $requisition->items);
        $this->assertSame(2, $requisition->items->first()->quantity);
        $this->assertStringContainsString($requester->username, $requisition->items->first()->notes);
        $this->assertEqualsWithDelta($order->total(), $requisition->subtotal(), 0.001);

        // Requester-facing status follows the chain: no PO yet → processing.
        $this->assertSame('processing', $order->displayStatus());
    }

    public function test_a_declined_order_carries_the_reason_back_to_the_requester()
    {
        $item = $this->shelfItem();
        $requester = $this->endUser();

        $this->actingAs($requester)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();

        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.decide', $order->id), [
                'decision' => 'declined',
                'decision_notes' => 'Out of cycle — ask again in September.',
            ])
            ->assertRedirect();

        $this->actingAs($requester)
            ->get(route('store.orders'))
            ->assertOk()
            ->assertSee('Out of cycle', false);
    }

    public function test_a_decided_order_cannot_be_decided_again()
    {
        $item = $this->shelfItem();
        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();
        $staff = $this->procurement();

        $this->actingAs($staff)->post(route('procurement.queue.decide', $order->id), ['decision' => 'declined']);
        $this->actingAs($staff)->post(route('procurement.queue.decide', $order->id), ['decision' => 'approved']);

        $this->assertSame('declined', $order->fresh()->status);
    }

    public function test_the_lifecycle_emails_fire_on_request_and_decision()
    {
        Mail::fake();

        $item = $this->shelfItem();
        $requester = $this->endUser();

        $this->actingAs($requester)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);

        Mail::assertSent(StoreOrderStatusMail::class, fn ($mail) => $mail->event === 'requested' && $mail->hasTo($requester->email));

        $order = StoreOrder::first();

        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.decide', $order->id), ['decision' => 'approved']);

        Mail::assertSent(StoreOrderStatusMail::class, fn ($mail) => $mail->event === 'approved' && $mail->hasTo($requester->email));
    }

    public function test_a_vendor_test_send_reaches_only_the_tester_and_changes_nothing()
    {
        Mail::fake();

        $staff = $this->procurement();
        $supplier = Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => 'rep1@cdw.ca,rep2@cdw.ca']);
        $item = $this->shelfItem(['supplier_id' => $supplier->id]);

        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();
        $order->update(['status' => 'approved']);

        $this->actingAs($staff)
            ->post(route('procurement.queue.send-vendor'), ['orders' => [$order->id], 'test' => 1])
            ->assertRedirect();

        Mail::assertSent(StoreVendorOrderMail::class, fn ($mail) => $mail->test
            && $mail->hasTo($staff->email) && ! $mail->hasTo('rep1@cdw.ca'));

        $this->assertSame('approved', $order->fresh()->status);
        $this->assertNull($order->fresh()->vendor_sent_at);
    }

    public function test_a_real_vendor_send_goes_to_the_reps_and_flips_the_order()
    {
        Mail::fake();

        $supplier = Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => 'rep1@cdw.ca,rep2@cdw.ca']);
        $item = $this->shelfItem(['supplier_id' => $supplier->id]);
        $requester = $this->endUser();

        $this->actingAs($requester)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();
        $order->update(['status' => 'approved', 'funding_account' => 'purchase_admin']);

        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.send-vendor'), ['orders' => [$order->id]])
            ->assertRedirect();

        Mail::assertSent(StoreVendorOrderMail::class, fn ($mail) => ! $mail->test
            && $mail->hasTo('rep1@cdw.ca') && $mail->hasTo('rep2@cdw.ca')
            && $mail->hasCc('devicesadmins@ecuad.ca') && $mail->hasCc('assetsadmins@ecuad.ca'));
        Mail::assertSent(StoreOrderStatusMail::class, fn ($mail) => $mail->event === 'ordered' && $mail->hasTo($requester->email));

        $order->refresh();
        $this->assertSame('ordered', $order->status);
        $this->assertNotNull($order->vendor_sent_at);

        // Sent is not placed. CDW quotes the request before ordering it, so
        // the requester is told it is with the vendor rather than on order.
        $this->assertSame('with_vendor', $order->displayStatus());
    }

    public function test_a_batch_vendor_send_groups_orders_into_one_email()
    {
        Mail::fake();

        $supplier = Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => 'rep1@cdw.ca']);
        $item = $this->shelfItem(['supplier_id' => $supplier->id]);

        foreach ([1, 2] as $qty) {
            $this->actingAs($this->endUser())->post(route('store.orders.store'), [
                'items' => [['catalog_item_id' => $item->id, 'quantity' => $qty]],
            ]);
        }
        StoreOrder::query()->update(['status' => 'approved', 'funding_account' => 'purchase_admin']);
        $ids = StoreOrder::pluck('id')->all();

        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.send-vendor'), ['orders' => $ids])
            ->assertRedirect();

        // One email carrying both orders, every order flipped.
        Mail::assertSent(StoreVendorOrderMail::class, 1);
        Mail::assertSent(StoreVendorOrderMail::class, fn ($mail) => $mail->orders->count() === 2
            && str_contains($mail->references(), 'ECU-STORE-'.$ids[0])
            && str_contains($mail->references(), 'ECU-STORE-'.$ids[1]));

        $this->assertSame(2, StoreOrder::where('status', 'ordered')->whereNotNull('vendor_sent_at')->count());
    }

    public function test_the_approver_list_outranks_the_orders_permission_once_set()
    {
        Mail::fake();

        $item = $this->shelfItem();
        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();

        // A plain user named on the approver list can decide…
        $listedApprover = $this->endUser();
        StoreApprover::create(['user_id' => $listedApprover->id]);

        $this->actingAs($listedApprover)
            ->post(route('procurement.queue.decide', $order->id), ['decision' => 'approved'])
            ->assertRedirect();
        $this->assertSame('approved', $order->fresh()->status);

        // …while an unlisted plain user cannot, even for a fresh order.
        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $second = StoreOrder::orderBy('id', 'desc')->first();

        $this->actingAs($this->endUser())
            ->post(route('procurement.queue.decide', $second->id), ['decision' => 'approved'])
            ->assertForbidden();

        // Superusers always can.
        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.decide', $second->id), ['decision' => 'declined'])
            ->assertRedirect();
        $this->assertSame('declined', $second->fresh()->status);
    }

    public function test_faculty_orders_carry_the_program_flag()
    {
        Mail::fake();

        $item = $this->shelfItem();
        $faculty = $this->endUser();
        $group = Group::create(['name' => 'Regular Faculty']);
        DB::table('users_groups')->insert([
            'user_id' => $faculty->id,
            'group_id' => $group->id,
        ]);

        $this->actingAs($faculty)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->assertSame('faculty', StoreOrder::first()->program);
        $this->assertTrue(StoreOrder::first()->isFacultyProgram());

        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->assertNull(StoreOrder::orderBy('id', 'desc')->first()->program);
    }

    public function test_a_shared_cart_skips_the_assigned_machine_machinery()
    {
        Mail::fake();

        $item = $this->shelfItem();

        // A tech in the Shared Purchasers group orders three lab machines.
        $tech = $this->endUser();
        $group = Group::create(['name' => 'Shared Purchasers']);
        DB::table('users_groups')->insert(['user_id' => $tech->id, 'group_id' => $group->id]);

        $lab = Location::factory()->create(['name' => 'Animation Lab B2205']);

        $this->actingAs($tech)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
            'order_usage' => 'shared',
            'location_id' => $lab->id,
        ]);

        $order = StoreOrder::first();
        $this->assertTrue($order->isShared());
        $this->assertSame($lab->id, $order->location_id);
        $this->assertNull($order->program);

        // The provisioned asset is shared from birth: usage tag, no name,
        // and already seated in the room it was ordered for.
        $asset = Asset::where('order_number', $order->reference())->first();
        $this->assertSame('Shared', $asset->lease_usage);
        $this->assertEmpty($asset->name);
        $this->assertSame($lab->id, $asset->rtd_location_id);

        // Their /my journey tracker ignores the cart — it is not their
        // machine arriving.
        $page = $this->actingAs($tech)->get(route('my'))->assertOk();
        $page->assertDontSee($order->reference(), false);

        // Someone not in the group posting order_usage=shared falls back
        // to a plain assigned order.
        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
            'order_usage' => 'shared',
            'location_id' => $lab->id,
        ]);
        $sneaky = StoreOrder::orderBy('id', 'desc')->first();
        $this->assertFalse($sneaky->isShared());
        $this->assertNull($sneaky->location_id);
    }

    public function test_a_shared_cart_must_name_the_space_it_is_for()
    {
        Mail::fake();

        $item = $this->shelfItem();
        $tech = $this->endUser();
        $group = Group::create(['name' => 'Shared Purchasers']);
        DB::table('users_groups')->insert(['user_id' => $tech->id, 'group_id' => $group->id]);

        $this->actingAs($tech)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
            'order_usage' => 'shared',
        ])->assertSessionHasErrors('location_id');

        $this->assertSame(0, StoreOrder::count());

        // Someone who cannot place a shared cart is never asked for a room —
        // their order is simply their own.
        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
            'order_usage' => 'shared',
        ])->assertSessionHasNoErrors();

        $this->assertFalse(StoreOrder::first()->isShared());
    }

    public function test_the_cart_offers_the_space_picker_only_to_shared_purchasers()
    {
        Location::factory()->create(['name' => 'Animation Lab B2205']);
        $this->shelfItem();

        $tech = $this->endUser();
        $group = Group::create(['name' => 'Shared Purchasers']);
        DB::table('users_groups')->insert(['user_id' => $tech->id, 'group_id' => $group->id]);

        $this->actingAs($tech)->get(route('store.index'))
            ->assertOk()
            ->assertSee('Animation Lab B2205')
            ->assertSee('name="location_id"', false);

        $this->actingAs($this->endUser())->get(route('store.index'))
            ->assertOk()
            ->assertDontSee('name="location_id"', false);
    }

    public function test_the_shipment_webhook_updates_status_and_notifies()
    {
        Mail::fake();

        $item = $this->shelfItem();
        $requester = $this->endUser();

        $this->actingAs($requester)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();
        $order->update(['status' => 'ordered', 'vendor_sent_at' => now()]);

        $this->actingAsForApi($this->procurement())
            ->postJson(route('api.store-orders.shipment', $order->id), [
                'status' => 'shipped',
                'tracking_number' => '1Z999AA10123456784',
                'serials' => ['C02XYZ987'],
            ])
            ->assertOk();

        $order->refresh();
        $this->assertSame('shipped', $order->displayStatus());
        $this->assertSame('1Z999AA10123456784', $order->tracking_number);
        Mail::assertSent(StoreOrderStatusMail::class, fn ($mail) => $mail->event === 'shipped' && $mail->hasTo($requester->email));

        $this->actingAsForApi($this->procurement())
            ->postJson(route('api.store-orders.shipment', $order->id), ['status' => 'arrived'])
            ->assertOk();

        $this->assertSame('arrived', $order->fresh()->displayStatus());
        Mail::assertSent(StoreOrderStatusMail::class, fn ($mail) => $mail->event === 'arrived');
    }

    public function test_the_shipment_webhook_rejects_undecided_orders()
    {
        $item = $this->shelfItem();
        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->actingAsForApi($this->procurement())
            ->postJson(route('api.store-orders.shipment', StoreOrder::first()->id), ['status' => 'shipped'])
            ->assertStatus(422);
    }

    public function test_the_catalog_api_lists_the_shelf_and_the_wider_catalog()
    {
        $shelf = $this->shelfItem(['name' => 'On the shelf']);
        $this->shelfItem(['name' => 'Off the shelf', 'show_in_store' => false, 'vendor_sku' => '999111']);

        $all = $this->actingAsForApi($this->procurement())
            ->getJson(route('api.catalog-items.index'))
            ->assertOk()
            ->json('payload');

        $this->assertSame(2, $all['total']);

        $inStore = $this->actingAsForApi($this->procurement())
            ->getJson(route('api.catalog-items.index', ['in_store' => 1]))
            ->assertOk()
            ->json('payload');

        $this->assertSame(1, $inStore['total']);
        $this->assertSame($shelf->id, $inStore['rows'][0]['id']);
    }

    public function test_the_catalog_api_curates_one_row_without_disturbing_the_rest()
    {
        $item = $this->shelfItem(['store_sort' => 7]);
        $model = $item->model_id;

        $this->actingAsForApi($this->procurement())
            ->patchJson(route('api.catalog-items.update', $item->id), ['show_in_store' => false])
            ->assertOk();

        $item->refresh();
        $this->assertFalse((bool) $item->show_in_store);
        // Untouched fields stay untouched — hiding a row is not a reset.
        $this->assertSame(7, (int) $item->store_sort);
        $this->assertSame($model, $item->model_id);

        $this->actingAsForApi($this->procurement())
            ->patchJson(route('api.catalog-items.update', $item->id), ['store_sort' => 2, 'model_id' => null])
            ->assertOk();

        $item->refresh();
        $this->assertSame(2, (int) $item->store_sort);
        $this->assertNull($item->model_id);
    }

    /**
     * Prices drift — Apple's retail number once overwrote the reseller's
     * bundle price on two rows, and a reseller correction arrived on a
     * third. The import cannot fix a row it did not create, so without this
     * the only route was a database console.
     */
    public function test_the_catalog_api_corrects_a_price()
    {
        $item = $this->shelfItem(['unit_cost' => null, 'store_sort' => 4]);
        $item->estimated_cost = 3499;
        $item->price_type = 'list';
        $item->save();

        $this->actingAsForApi($this->procurement())
            ->patchJson(route('api.catalog-items.update', $item->id), ['estimated_cost' => 2800])
            ->assertOk();

        $item->refresh();
        $this->assertSame(2800.0, (float) $item->estimated_cost);
        // A hand-corrected estimate is ours, not Apple's, so the row stops
        // claiming the number came from a retail page.
        $this->assertSame('estimate', $item->price_type);
        $this->assertSame(4, (int) $item->store_sort, 'a price fix is not a reset');
    }

    /** A quote outranks an estimate, and clearing it is a real change. */
    public function test_the_catalog_api_can_set_and_clear_a_quoted_price()
    {
        $item = $this->shelfItem(['unit_cost' => null]);
        $item->estimated_cost = 2100;
        $item->save();

        $this->actingAsForApi($this->procurement())
            ->patchJson(route('api.catalog-items.update', $item->id), [
                'unit_cost' => 2377.19, 'price_type' => 'quoted',
            ])->assertOk();

        $item->refresh();
        $this->assertSame(2377.19, (float) $item->unit_cost);
        $this->assertSame(2377.19, $item->effectiveCost());

        $this->actingAsForApi($this->procurement())
            ->patchJson(route('api.catalog-items.update', $item->id), ['unit_cost' => null])
            ->assertOk();

        $item->refresh();
        $this->assertNull($item->unit_cost);
        $this->assertSame(2100.0, $item->effectiveCost(), 'back to the estimate');
    }

    public function test_the_catalog_api_creates_and_retires_a_row()
    {
        $model = AssetModel::factory()->create();

        $created = $this->actingAsForApi($this->procurement())
            ->postJson(route('api.catalog-items.store'), [
                'name' => 'Apple Thunderbolt 5 Pro Cable 1m',
                'category' => 'Accessories',
                'mfr_part_number' => 'MDW94AM/A',
                'unit_cost' => 80,
                'model_id' => $model->getKey(),
                'show_in_store' => true,
            ])
            ->assertOk()
            ->json('payload');

        $this->assertTrue($created['show_in_store']);
        $this->assertSame('quoted', $created['price_type']);

        $this->actingAsForApi($this->procurement())
            ->deleteJson(route('api.catalog-items.destroy', $created['id']))
            ->assertOk();

        $retired = CatalogItem::withTrashed()->find($created['id']);
        $this->assertNotNull($retired->deleted_at);
        $this->assertFalse((bool) $retired->show_in_store);
        $this->assertSame(0, CatalogItem::inStore()->count());
    }

    public function test_curating_the_catalog_needs_the_orders_permission()
    {
        $item = $this->shelfItem();

        $this->actingAsForApi($this->endUser())
            ->patchJson(route('api.catalog-items.update', $item->id), ['show_in_store' => false])
            ->assertForbidden();

        $this->assertTrue((bool) $item->fresh()->show_in_store);
    }

    public function test_only_approved_orders_can_be_pulled()
    {
        $item = $this->shelfItem();
        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();

        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.pull'), ['title' => 'Sneaky pull', 'orders' => [$order->id]])
            ->assertRedirect();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, Requisition::count());
    }

    public function test_the_order_email_carries_the_whole_line_not_a_split_name()
    {
        // A catalog name is full of pipe characters, and the email used to
        // render lines into a markdown table — so every pipe opened a new
        // column and a screen size arrived under the heading "Qty".
        $item = $this->shelfItem([
            'name' => 'MacBook Pro | 16" | M5 Max | 48GB | 2TB | Black | Nano-texture',
            'family' => 'MacBook Pro',
            'screen_size' => '16',
            'chip' => 'M5 Max',
            'ram_gb' => 48,
            'storage' => '2TB',
            'display_finish' => 'nano',
            'mfr_part_number' => 'MDH84LL/A',
        ]);

        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 2]],
            'notes' => 'For the print studio refresh.',
        ]);

        $body = (new StoreOrderStatusMail(StoreOrder::first(), 'requested'))->render();

        // The line's own detail, rather than whatever survived the pipes.
        $this->assertStringContainsString('MacBook Pro', $body);
        $this->assertStringContainsString('MDH84LL/A', $body);
        $this->assertStringContainsString('48GB unified memory', $body);
        $this->assertStringContainsString('2TB SSD', $body);
        $this->assertStringContainsString('16-inch Nano-texture', $body);
        $this->assertStringContainsString('For the print studio refresh.', $body);

        // Two of them, priced as two of them.
        $this->assertStringContainsString("\u{00D7} 2", $body);
        $this->assertStringContainsString('5,317', $body);
    }

    public function test_the_vendor_order_request_carries_whole_lines_and_a_part_list_csv()
    {
        $supplier = Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => 'rep1@cdw.ca']);

        // The worst case for the old markdown table: six pipes in the name.
        $item = $this->shelfItem([
            'supplier_id' => $supplier->id,
            'name' => 'MacBook Pro | 16" | M5 Max | 48GB | 2TB | Black | Nano-texture',
            'family' => 'MacBook Pro',
            'mfr_part_number' => 'Z1N1-2310166117-2',
            'vendor_sku' => '9219353',
            'warranty_months' => 36,
            'bundle_url' => 'https://www.cdw.ca/accountcenter/ManagedList/BundleList/abc123',
        ]);

        $requester = $this->endUser();
        $this->actingAs($requester)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 3]],
        ]);

        $order = StoreOrder::first();
        $order->update([
            'status' => 'approved',
            'funding_account' => 'lease_admin',
            'lease_schedule' => '301452-009',
        ]);

        $mail = new StoreVendorOrderMail(StoreOrder::where('id', $order->id)->get());
        $body = $mail->render();

        // The whole configuration survives, not just its first pipe-segment.
        $this->assertStringContainsString('MacBook Pro | 16" | M5 Max | 48GB | 2TB | Black | Nano-texture', $body);
        $this->assertStringContainsString('Z1N1-2310166117-2', $body);
        $this->assertStringContainsString('9219353', $body);
        $this->assertStringContainsString('3 years', $body);
        $this->assertStringContainsString('301452-009', $body);

        // And the part list CDW actually keys from.
        $csv = (new VendorOrderCsv(StoreOrder::where('id', $order->id)->get()))->contents();

        $this->assertStringContainsString('MFR #', $csv);
        $this->assertStringContainsString('CDW EDC #', $csv);
        $this->assertStringContainsString('Z1N1-2310166117-2', $csv);
        $this->assertStringContainsString('9219353', $csv);
        $this->assertStringContainsString('301452-009', $csv);
        $this->assertStringContainsString('Lease', $csv);
        $this->assertStringContainsString('3 years', $csv);
        $this->assertStringContainsString('ECU-STORE-'.$order->id, $csv);

        // A name full of commas and quotes is one field, not several — the
        // reseller opens this in Excel and a split line is a wrong order.
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv))));
        $this->assertCount(2, $lines);
        $this->assertSame(11, count(str_getcsv($lines[1])));
        $this->assertSame('3', str_getcsv($lines[1])[4]);
    }

    public function test_an_order_with_no_account_is_not_sent_to_the_vendor()
    {
        Mail::fake();

        $supplier = Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => 'rep1@cdw.ca']);
        $item = $this->shelfItem(['supplier_id' => $supplier->id]);

        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $order = StoreOrder::first();
        $order->update(['status' => 'approved']);

        // No account: CDW would not know which blanket PO to place against.
        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.send-vendor'), ['orders' => [$order->id]])
            ->assertRedirect();

        Mail::assertNotSent(StoreVendorOrderMail::class);
        $this->assertSame('approved', $order->fresh()->status);

        // A lease with no schedule is just as unplaceable.
        $order->update(['funding_account' => 'lease_admin']);
        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.send-vendor'), ['orders' => [$order->id]])
            ->assertRedirect();
        Mail::assertNotSent(StoreVendorOrderMail::class);

        // Named schedule, and it goes.
        $order->update(['lease_schedule' => '301452-010']);
        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.send-vendor'), ['orders' => [$order->id]])
            ->assertRedirect();
        Mail::assertSent(StoreVendorOrderMail::class, 1);
        $this->assertSame('ordered', $order->fresh()->status);
    }

    public function test_a_test_send_still_carries_the_csv_and_changes_nothing()
    {
        Mail::fake();

        $supplier = Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => 'rep1@cdw.ca']);
        $item = $this->shelfItem(['supplier_id' => $supplier->id]);
        $staff = $this->procurement();

        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();
        $order->update(['status' => 'approved']);

        // No account set, yet the test send goes: it exists to check the
        // layout, and the attachment is the part most worth checking.
        $this->actingAs($staff)
            ->post(route('procurement.queue.send-vendor'), ['orders' => [$order->id], 'test' => 1])
            ->assertRedirect();

        Mail::assertSent(StoreVendorOrderMail::class, function ($mail) use ($staff) {
            return $mail->test
                && $mail->hasTo($staff->email)
                && count($mail->attachments()) === 1;
        });

        $this->assertSame('approved', $order->fresh()->status);
        $this->assertNull($order->fresh()->vendor_sent_at);
    }

    public function test_the_quote_comes_back_then_is_confirmed()
    {
        Mail::fake();

        $supplier = Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => 'rep1@cdw.ca']);
        $item = $this->shelfItem(['supplier_id' => $supplier->id]);

        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $order = StoreOrder::first();

        // A quote cannot be recorded before the request has gone out.
        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.quote', $order->id), ['quote_number' => 'Q-1'])
            ->assertRedirect();
        $this->assertNull($order->fresh()->quote_number);

        $order->update(['status' => 'approved', 'funding_account' => 'purchase_admin']);
        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.send-vendor'), ['orders' => [$order->id]]);

        $this->assertSame('with_vendor', $order->fresh()->displayStatus());

        // CDW answers with a quote — recorded, but nothing is placed yet.
        $this->actingAs($this->procurement())->post(route('procurement.queue.quote', $order->id), [
            'quote_number' => 'QUOTE-88213',
            'quote_total' => 2799.00,
            'quote_expires_at' => now()->addDays(30)->toDateString(),
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('QUOTE-88213', $order->quote_number);
        $this->assertNotNull($order->quote_received_at);
        $this->assertNull($order->confirmed_at);
        $this->assertSame('quoted', $order->displayStatus());
        $this->assertFalse($order->quoteIsExpired());

        // Signing it off is what places the order.
        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.quote', $order->id), ['confirm' => 1])
            ->assertRedirect();

        $order->refresh();
        $this->assertNotNull($order->confirmed_at);
        $this->assertSame('ordered', $order->displayStatus());
        $this->assertSame('QUOTE-88213', $order->quote_number);
    }

    public function test_an_expired_unconfirmed_quote_is_flagged()
    {
        $order = StoreOrder::create([
            'user_id' => $this->endUser()->id,
            'status' => 'ordered',
            'vendor_sent_at' => now()->subDays(60),
            'quote_received_at' => now()->subDays(60),
            'quote_expires_at' => now()->subDay(),
        ]);

        $this->assertTrue($order->quoteIsExpired());

        // Confirmed in time, so the expiry no longer matters.
        $order->update(['confirmed_at' => now()->subDays(2)]);
        $this->assertFalse($order->fresh()->quoteIsExpired());
    }

    public function test_received_is_read_off_the_arrival_the_webhook_lands()
    {
        Mail::fake();

        $supplier = Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => 'rep1@cdw.ca']);
        $item = $this->shelfItem(['supplier_id' => $supplier->id]);

        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $order = StoreOrder::first();
        $order->update(['status' => 'ordered', 'vendor_sent_at' => now(), 'confirmed_at' => now()]);

        $this->assertFalse($order->isReceived());

        $this->actingAsForApi($this->procurement())->postJson(route('api.store-orders.shipment', $order->id), [
            'status' => 'arrived',
            'tracking_number' => '1Z999',
            'serials' => ['C02XY1234'],
        ])->assertOk();

        $order->refresh();
        $this->assertTrue($order->isReceived());
        $this->assertSame('arrived', $order->displayStatus());
    }

    public function test_a_warranty_term_reads_in_years_when_it_divides()
    {
        $this->assertSame('3 years', $this->shelfItem(['warranty_months' => 36])->warrantyLabel());
        $this->assertSame('1 year', $this->shelfItem(['warranty_months' => 12, 'vendor_sku' => '2'])->warrantyLabel());
        $this->assertSame('18 months', $this->shelfItem(['warranty_months' => 18, 'vendor_sku' => '3'])->warrantyLabel());
        $this->assertNull($this->shelfItem(['warranty_months' => null, 'vendor_sku' => '4'])->warrantyLabel());
    }

    public function test_a_pasted_part_number_is_cleaned_of_invisible_whitespace()
    {
        // Four EDC values reached the live catalog carrying a trailing
        // U+00A0, pasted from CDW's Excel export. PHP's trim() leaves those
        // alone, and they ship into the part list the reseller keys from.
        $this->assertSame('9094668', CatalogItem::tidyIdentifier("9094668\u{00A0}"));
        $this->assertSame('9094668', CatalogItem::tidyIdentifier("\u{FEFF}9094668 "));
        $this->assertSame('MDE54LL/A', CatalogItem::tidyIdentifier("MDE54LL/A\u{200B}"));
        $this->assertSame('', CatalogItem::tidyIdentifier("\u{00A0} "));

        // A space inside a name is left alone — only identifiers are tidied,
        // and only characters that cannot be seen are removed.
        $this->assertSame('4X20M26268', CatalogItem::tidyIdentifier(' 4X20M26268 '));

        $item = $this->shelfItem();

        $this->actingAsForApi($this->procurement())
            ->patchJson(route('api.catalog-items.update', $item->id), [
                'vendor_sku' => "9094640\u{00A0}",
                'mfr_part_number' => " MGDT4LL/A\u{00A0}",
            ])->assertOk();

        $item->refresh();
        $this->assertSame('9094640', $item->vendor_sku);
        $this->assertSame('MGDT4LL/A', $item->mfr_part_number);
    }
}
