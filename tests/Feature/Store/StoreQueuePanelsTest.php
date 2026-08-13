<?php

namespace Tests\Feature\Store;

use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\CsiSchedule;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\User;
use Tests\TestCase;

/**
 * The procurement queue's CDW-facing panels, and the catalog table's part
 * numbers. These are the surfaces the new order process is operated from,
 * and a Blade partial that fails to render is invisible to a model test.
 */
class StoreQueuePanelsTest extends TestCase
{
    public function test_the_queue_offers_only_open_lease_schedules_and_gates_the_send()
    {
        CsiSchedule::create(['schedule_name' => '301452-009', 'lease_number' => '301452',
            'term_end_date' => now()->addYears(4)->toDateString()]);
        CsiSchedule::create(['schedule_name' => '301452-010', 'lease_number' => '301452',
            'term_end_date' => now()->addYears(5)->toDateString()]);
        CsiSchedule::create(['schedule_name' => '301452-005', 'lease_number' => '301452',
            'term_end_date' => now()->subYear()->toDateString()]);

        $supplier = Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => 'rep@cdw.ca']);
        $item = CatalogItem::create(['name' => 'ThinkPad T14', 'family' => 'ThinkPad T14',
            'category' => 'Laptops', 'product_type' => 'standard', 'vendor_sku' => '8394675',
            'mfr_part_number' => '21QKS09B00', 'warranty_months' => 48, 'unit_cost' => 2200,
            'price_type' => 'quoted', 'show_in_store' => true, 'supplier_id' => $supplier->id,
            'model_id' => AssetModel::factory()->create()->getKey()]);

        $this->actingAs(User::factory()->create())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $staff = User::factory()->superuser()->create();

        // Pending: the account picker is on the decision form, open schedules
        // offered and the closed one withheld.
        $r = $this->actingAs($staff)->get(route('procurement.approvals', ['status' => 'pending']))->assertOk();
        $r->assertSee('301452-009', false)->assertSee('301452-010', false)
            ->assertDontSee('301452-005', false)
            ->assertSee('No account set', false)->assertSee('Lease', false);

        // Approved with no account: send is disabled and says why.
        StoreOrder::first()->update(['status' => 'approved']);
        $this->actingAs($staff)->get(route('procurement.approvals', ['status' => 'approved']))->assertOk()
            ->assertSee('Set an account on every selected order first', false)
            ->assertSee('disabled', false);

        // Sent: the quote panel and the not-received badge appear.
        StoreOrder::first()->update(['status' => 'ordered', 'vendor_sent_at' => now(),
            'funding_account' => 'lease_admin', 'lease_schedule' => '301452-009']);
        $this->actingAs($staff)->get(route('procurement.approvals', ['status' => 'ordered']))->assertOk()
            ->assertSee('CDW quote', false)
            ->assertSee('Confirm and place', false)
            ->assertSee('Not received', false)
            ->assertSee('Lease · Admin · 301452-009', false);

        // Arrived: flips to Received.
        StoreOrder::first()->update(['arrived_at' => now(), 'confirmed_at' => now()]);
        $this->actingAs($staff)->get(route('procurement.approvals', ['status' => 'ordered']))->assertOk()
            ->assertSee('Received', false);

        // The catalog table shows and can edit both part numbers.
        $this->actingAs($staff)->get(route('procurement.store-admin'))->assertOk()
            ->assertSee('21QKS09B00', false)->assertSee('8394675', false)
            ->assertSee('name="mfr_part_number"', false)->assertSee('name="vendor_sku"', false)
            ->assertSee('4 years', false);
    }
}
