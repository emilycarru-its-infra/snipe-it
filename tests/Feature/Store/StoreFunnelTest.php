<?php

namespace Tests\Feature\Store;

use App\Mail\StoreOrderStatusMail;
use App\Mail\StoreVendorOrderMail;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\Group;
use App\Models\Requisition;
use App\Models\StoreApprover;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\User;
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
        $this->actingAs($this->procurement())
            ->get('/reports/procurement/po-builder?requisition=7')
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

        $this->actingAs($user)->get(route('procurement.index'))->assertForbidden();
        $this->actingAs($user)->get(route('procurement.queue'))->assertForbidden();
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
        $order->update(['status' => 'approved']);

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
        $this->assertSame('ordered', $order->displayStatus());
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
        StoreOrder::query()->update(['status' => 'approved']);
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
}
