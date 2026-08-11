<?php

namespace Tests\Feature\Orders;

use App\Models\CatalogItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use Tests\TestCase;

/**
 * /procurement as the single destination for procurement work.
 *
 * The page used to be a set of tiles pointing at six other pages. It now
 * carries those pages' tables itself, so what matters here is that each tab
 * is present, that the queue keeps the decision controls that make it useful,
 * and that the formatters every table names by string are actually loaded —
 * a table whose formatter is missing renders the function's name in each
 * cell rather than failing, so nothing else would catch it.
 */
class ProcurementHubTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function pendingOrder(): StoreOrder
    {
        $item = CatalogItem::create([
            'name' => 'MacBook Pro | 16" | M5 Max',
            'category' => 'Laptops',
            'vendor_sku' => '9219355',
            'unit_cost' => 5949.82,
            'price_type' => 'quoted',
        ]);

        $order = StoreOrder::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
        ]);

        StoreOrderItem::create([
            'store_order_id' => $order->id,
            'catalog_item_id' => $item->id,
            'description' => $item->name,
            'quantity' => 1,
            'unit_cost' => 5949.82,
        ]);

        return $order;
    }

    public function test_in_flight_work_shows_as_pipeline_cards_on_the_board()
    {
        $this->pendingOrder();
        Requisition::create(['title' => 'Ministry ask', 'status' => 'submitted', 'requisition_number' => 'REQM-77']);

        $response = $this->actingAs($this->superuser())
            ->followingRedirects()->get(route('procurement.index'))
            ->assertOk();

        // The approval queue and open requisitions ride the Orders Pipeline
        // as cards — no side tables, no duplicate tabs.
        $response->assertSee('ECU-STORE-');
        $response->assertSee('REQM-77');
        $response->assertSee(trans('admin/purchase-orders/general.pipeline_chip_awaiting'));
        $response->assertSee(e(trans('admin/purchase-orders/general.pipeline_chip_reqm')));
        $response->assertDontSee('href="#suppliers"', false);
        $response->assertDontSee('href="#depreciation"', false);
    }

    public function test_reports_carry_a_sticky_stage_pill_filter_strip()
    {
        $response = $this->actingAs($this->superuser())
            ->followingRedirects()->get(route('procurement.index'))
            ->assertOk();

        // Five aligned stage columns of pills — filters over the fully
        // rendered report stream, not tab selectors.
        foreach (['budgeting', 'ordering', 'deploying', 'reconciling', 'completed'] as $stage) {
            $response->assertSee('class="pr-pill-col" data-report-stage="'.$stage.'"', false);
        }
        $response->assertSee('data-pr-report="proc-report_po_budget"', false);
        $response->assertSee('id="proc-report_po_budget"', false);
    }

    public function test_queue_decisions_live_on_the_queue_page()
    {
        $this->pendingOrder();

        $this->actingAs($this->superuser())
            ->get(route('procurement.queue'))
            ->assertOk()
            ->assertSee(trans('admin/store/general.queue_approve'))
            ->assertSee(trans('admin/store/general.queue_decline'))
            ->assertSee('MacBook Pro | 16" | M5 Max');
    }

    /**
     * Approvers is configuration, not content — it belongs behind a button,
     * not in a box on the page it governs.
     */
    public function test_order_approvers_is_behind_a_modal_rather_than_on_the_page()
    {
        $this->actingAs($this->superuser())
            ->followingRedirects()->get(route('procurement.index'))
            ->assertOk()
            ->assertSee('data-target="#approversModal"', false)
            ->assertSee('id="approversModal"', false);
    }

    public function test_a_non_superuser_gets_no_approvers_control()
    {
        $user = User::factory()->create();
        $user->permissions = json_encode(['orders.view' => '1']);
        $user->save();

        $this->actingAs($user)
            ->followingRedirects()->get(route('procurement.index'))
            ->assertOk()
            ->assertDontSee('id="approversModal"', false);
    }

    public function test_procurement_is_a_top_level_nav_item()
    {
        $this->actingAs($this->superuser())
            ->followingRedirects()->get(route('procurement.index'))
            ->assertOk()
            ->assertSee('class="topbar-nav-label">'.trans('general.procurement'), false);
    }

    /**
     * The dedicated pages the hub gathers must keep working — the hub is an
     * addition, not a replacement, and the queue in particular now renders
     * from a partial shared with the hub.
     */
    public function test_the_pages_the_hub_gathers_still_render_on_their_own()
    {
        $this->pendingOrder();
        Requisition::create(['title' => 'Faculty refresh', 'status' => 'draft']);
        PurchaseOrder::create(['po_number' => 'P0025747', 'status' => 'open']);

        $user = $this->superuser();

        foreach ([
            route('procurement.queue'),
            route('requisitions.index'),
            route('purchase-orders.index'),
            route('orders.index'),
            route('suppliers.index'),
            route('lease-decisions.index'),
            route('depreciations.index'),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_the_requisitions_page_renders_its_table_from_the_api()
    {
        $this->actingAs($this->superuser())
            ->get(route('requisitions.index'))
            ->assertOk()
            ->assertSee(route('api.requisitions.index'), false)
            ->assertSee('window.requisitionsLinkFormatter', false);
    }
}
