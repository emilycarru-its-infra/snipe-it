<?php

namespace Tests\Feature\Orders;

use App\Models\Consumable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierAccount;
use App\Models\User;
use App\Services\SupplierAccounts;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Two assumptions that only held while there was one vendor and no ink:
 * that every supplier bills through the reseller's account grid, and that
 * everything on an order is capital.
 */
class VendorAccountsAndConsumablesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SupplierAccounts::flush();
    }

    private function reseller(): Supplier
    {
        $supplier = Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => 'rep@cdw.ca']);

        // The migration already seeded these, unattached — attach them, the
        // way the migration does when the supplier exists at migrate time.
        foreach (SupplierAccounts::SEED_ACCOUNTS as $key => $account) {
            SupplierAccount::updateOrCreate(['key' => $key], [
                'supplier_id' => $supplier->id,
                'number' => $account['number'],
                'purpose' => $account['purpose'],
                'kind' => $account['kind'],
                'scope' => $account['scope'],
                'payee' => $account['payee'],
                'schedule_type' => $account['schedule'],
                'active' => true,
            ]);
        }

        SupplierAccounts::flush();

        return $supplier;
    }

    private function orderFor(Supplier $supplier, array $overrides = []): Order
    {
        $order = new Order;
        $order->order_number = $overrides['order_number'] ?? 'TEST-1';
        $order->supplier_id = $supplier->id;
        $order->status = 'ordered';
        $order->is_planned = false;
        $order->funding_account = $overrides['funding_account'] ?? null;
        $order->save();

        OrderItem::create([
            'order_id' => $order->id,
            'description' => 'Something the vendor sells',
            'quantity' => 1,
            'unit_cost' => 100,
        ]);

        return $order->fresh();
    }

    public function test_the_account_grid_belongs_to_the_supplier_that_holds_it()
    {
        $reseller = $this->reseller();
        $other = Supplier::create(['name' => 'Some Other Vendor']);

        $this->assertCount(4, SupplierAccounts::forSupplier($reseller->id));
        $this->assertSame([], SupplierAccounts::forSupplier($other->id));
        $this->assertSame([], SupplierAccounts::forSupplier(null));

        $this->assertTrue(SupplierAccounts::supplierHasAccounts($reseller->id));
        $this->assertFalse(SupplierAccounts::supplierHasAccounts($other->id));
    }

    public function test_an_unattached_account_still_counts_for_everyone()
    {
        // What a fresh install looks like: the migration seeded the grid before
        // any supplier existed to attach it to. Those accounts must not become
        // unusable.
        $anyone = Supplier::create(['name' => 'Whoever']);

        $this->assertCount(
            count(SupplierAccounts::SEED_ACCOUNTS),
            SupplierAccounts::forSupplier($anyone->id)
        );
    }

    public function test_an_order_to_a_vendor_with_no_accounts_needs_no_account()
    {
        // Production's shape: the grid exists and belongs to the reseller.
        $this->reseller();
        $other = Supplier::create(['name' => 'Some Other Vendor']);

        $order = $this->orderFor($other);

        // This was the bug: no account of this vendor's existed to pick, so the
        // order could never be ready and they could never be sent anything.
        $this->assertTrue($order->fundingResolved());
    }

    public function test_the_reseller_still_has_to_pick_an_account()
    {
        $reseller = $this->reseller();

        $this->assertFalse($this->orderFor($reseller)->fundingResolved());

        $chosen = $this->orderFor($reseller, [
            'order_number' => 'TEST-2',
            'funding_account' => 'purchase_admin',
        ]);

        $this->assertTrue($chosen->fundingResolved());
    }

    public function test_a_lease_account_still_needs_its_schedule()
    {
        $reseller = $this->reseller();

        $order = $this->orderFor($reseller, [
            'funding_account' => 'lease_admin',
        ]);

        $this->assertFalse($order->fundingResolved());

        $order->lease_schedule = '301452-009';
        $order->save();

        $this->assertTrue($order->fresh()->fundingResolved());
    }

    public function test_consumable_lines_do_not_consume_the_budget()
    {
        $purchaseOrder = PurchaseOrder::factory()->create([
            'po_number' => 'P0025936', 'budget' => 10000, 'fiscal_year' => 'FY2025-26',
        ]);

        $order = new Order;
        $order->order_number = 'P0025936-1';
        $order->status = 'ordered';
        $order->is_planned = false;
        $order->fiscal_year = 'FY2025-26';
        $order->purchase_order_id = $purchaseOrder->id;
        $order->save();

        $ink = Consumable::factory()->create();

        OrderItem::create([
            'order_id' => $order->id,
            'purchase_order_id' => $purchaseOrder->id,
            'description' => 'A device that is capital',
            'quantity' => 1,
            'unit_cost' => 2000,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'purchase_order_id' => $purchaseOrder->id,
            'item_type' => Consumable::class,
            'item_id' => $ink->id,
            'description' => 'iPF GP-200 PFI-320 Black Ink',
            'quantity' => 2,
            'unit_cost' => 207,
        ]);

        // The ink is on the order and on the invoice; it just is not capital.
        $this->assertEquals(2000.0, $purchaseOrder->fresh()->committedTotal());
        $this->assertEquals(2000.0, $purchaseOrder->fresh()->committedTotalForFy('FY2025-26'));
    }

    public function test_receiving_over_the_api_can_leave_consumable_stock_alone()
    {
        Passport::actingAs(User::factory()->superuser()->create());

        $order = new Order;
        $order->order_number = 'P0025936-1';
        $order->status = 'ordered';
        $order->is_planned = false;
        $order->save();

        $ink = Consumable::factory()->create(['qty' => 5]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_type' => Consumable::class,
            'item_id' => $ink->id,
            'description' => 'iPF GP-200 PFI-320 Black Ink',
            'quantity' => 2,
            'unit_cost' => 207,
        ]);

        $this->postJson(route('api.orders.receive', $order->id), ['adjust_stock' => false])
            ->assertOk()
            ->assertJsonPath('payload.received', 1)
            ->assertJsonPath('payload.stock_adjusted', false)
            ->assertJsonPath('payload.status', 'received');

        // The cartridges were used up years ago; receiving the paperwork must
        // not put them back on a shelf.
        $this->assertSame(5, $ink->fresh()->qty);
    }

    public function test_receiving_normally_still_moves_stock()
    {
        Passport::actingAs(User::factory()->superuser()->create());

        $order = new Order;
        $order->order_number = 'TEST-3';
        $order->status = 'ordered';
        $order->is_planned = false;
        $order->save();

        $ink = Consumable::factory()->create(['qty' => 5]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_type' => Consumable::class,
            'item_id' => $ink->id,
            'description' => 'Ink that actually arrived',
            'quantity' => 2,
            'unit_cost' => 207,
        ]);

        $this->postJson(route('api.orders.receive', $order->id))
            ->assertOk()
            ->assertJsonPath('payload.stock_adjusted', true);

        $this->assertSame(7, $ink->fresh()->qty);
    }

    public function test_an_order_with_nothing_open_answers_error()
    {
        Passport::actingAs(User::factory()->superuser()->create());

        $order = new Order;
        $order->order_number = 'TEST-4';
        $order->status = 'ordered';
        $order->is_planned = false;
        $order->save();

        OrderItem::create([
            'order_id' => $order->id,
            'description' => 'Already here',
            'quantity' => 1,
            'unit_cost' => 10,
            'received_at' => now(),
        ]);

        $this->postJson(route('api.orders.receive', $order->id))
            ->assertOk()
            ->assertJsonPath('status', 'error');
    }
}
