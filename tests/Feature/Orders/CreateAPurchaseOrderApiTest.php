<?php

namespace Tests\Feature\Orders;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Keying in a purchase order that arrived as a signed PDF, over the API.
 *
 * Finance still owns purchase order creation — the permission is the same one
 * the web form checks. What is new is that a store order waiting on its
 * purchase order no longer needs a browser to get one.
 */
class CreateAPurchaseOrderApiTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'po_number' => 'P0026050',
            'title' => 'Tablets, replenishment',
            'supplier_id' => Supplier::create(['name' => 'CDW Canada Inc'])->id,
            'fiscal_year' => 'FY2026-27',
            'budget' => 1580.42,
            'order_date' => '2026-08-31',
            'notes' => 'Keyed in from the signed PDF.',
        ], $overrides);
    }

    public function test_it_creates_the_purchase_order()
    {
        $user = User::factory()->superuser()->create();
        Passport::actingAs($user);

        $this->postJson(route('api.purchase-orders.store'), $this->payload())
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.po_number', 'P0026050');

        $purchaseOrder = PurchaseOrder::where('po_number', 'P0026050')->firstOrFail();

        $this->assertSame('open', $purchaseOrder->status);
        $this->assertSame('FY2026-27', $purchaseOrder->fiscal_year);
        $this->assertEquals(1580.42, (float) $purchaseOrder->budget);
        $this->assertSame($user->id, $purchaseOrder->created_by);
    }

    public function test_it_refuses_a_second_record_under_the_same_number()
    {
        Passport::actingAs(User::factory()->superuser()->create());

        $this->postJson(route('api.purchase-orders.store'), $this->payload())->assertOk();

        // `po_number` is what the orders/ingest webhook resolves against, so a
        // duplicate would split one purchase order's spend across two records.
        $this->postJson(route('api.purchase-orders.store'), $this->payload())
            ->assertOk()
            ->assertJsonPath('status', 'error');

        $this->assertSame(1, PurchaseOrder::where('po_number', 'P0026050')->count());
    }

    public function test_it_rejects_a_purchase_order_with_no_number()
    {
        Passport::actingAs(User::factory()->superuser()->create());

        $this->postJson(route('api.purchase-orders.store'), $this->payload(['po_number' => '']))
            ->assertOk()
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_a_user_without_the_permission_cannot_create_one()
    {
        Passport::actingAs(User::factory()->create());

        $this->postJson(route('api.purchase-orders.store'), $this->payload())->assertForbidden();

        $this->assertSame(0, PurchaseOrder::count());
    }
}
