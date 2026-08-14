<?php

namespace Tests\Feature\Store;

use App\Models\Requisition;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use Tests\TestCase;

/**
 * The two views over the approval queue, and clearing out the requests
 * nobody will ever act on again.
 */
class StoreQueueViewsTest extends TestCase
{
    private function order(string $status, float $unitCost = 2100, int $qty = 1): StoreOrder
    {
        $order = StoreOrder::create([
            'user_id' => User::factory()->create()->id,
            'status' => $status,
        ]);

        StoreOrderItem::create([
            'store_order_id' => $order->id,
            'description' => 'MacBook Air | 13" | M5 | 16GB | 1TB | Silver',
            'quantity' => $qty,
            'unit_cost' => $unitCost,
        ]);

        return $order->fresh();
    }

    public function test_the_queue_opens_on_cards_and_says_how_big_the_job_is(): void
    {
        $this->order('pending', 2100);
        $this->order('pending', 900);
        $this->order('approved', 5000);

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('procurement.approvals'))
            ->assertOk();

        // Cards, not the table, with no view parameter. Asserted on markup
        // rather than class names: the stylesheet names both, always.
        $response->assertSee('class="pq-card', false)
            ->assertDontSee('<table class="table table-striped pq-table"', false);

        // The summary counts and totals only what is still awaiting a decision.
        $response->assertSee(trans('admin/store/general.queue_summary_awaiting'))
            ->assertSee('3,000.00');
    }

    public function test_the_list_view_renders_a_table_instead(): void
    {
        $this->order('pending');

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('procurement.approvals', ['view' => 'table']))
            ->assertOk();

        $response->assertSee('<table class="table table-striped pq-table"', false)
            ->assertDontSee('class="pq-card', false);
    }

    public function test_an_unknown_view_falls_back_to_cards(): void
    {
        $this->order('pending');

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('procurement.approvals', ['view' => 'gallery']))
            ->assertOk()
            ->assertSee('class="pq-card', false);
    }

    public function test_the_view_choice_survives_a_status_filter(): void
    {
        $this->order('declined');

        $content = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('procurement.approvals', ['view' => 'table']))
            ->assertOk()
            ->getContent();

        // Every filter pill carries the view through, so picking a status does
        // not silently throw you back to cards.
        $this->assertStringContainsString(
            htmlspecialchars(route('procurement.approvals', ['status' => 'declined', 'view' => 'table'])),
            $content
        );
    }

    public function test_a_declined_request_can_be_deleted(): void
    {
        $order = $this->order('declined');

        $this->actingAs(User::factory()->superuser()->create())
            ->delete(route('procurement.queue.destroy', $order->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('store_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('store_order_items', ['store_order_id' => $order->id]);
    }

    public function test_a_pending_request_cannot_be_deleted(): void
    {
        $order = $this->order('pending');

        $this->actingAs(User::factory()->superuser()->create())
            ->delete(route('procurement.queue.destroy', $order->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('store_orders', ['id' => $order->id]);
    }

    public function test_a_declined_request_already_on_a_requisition_is_kept(): void
    {
        $order = $this->order('declined');
        $requisition = Requisition::create(['requisition_number' => 'REQ-TEST-1', 'title' => 'Test', 'status' => 'draft']);
        $order->update(['requisition_id' => $requisition->id]);

        $this->actingAs(User::factory()->superuser()->create())
            ->delete(route('procurement.queue.destroy', $order->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('store_orders', ['id' => $order->id]);
    }

    public function test_clearing_removes_every_dead_request_and_nothing_else(): void
    {
        $declined = $this->order('declined');
        $cancelled = $this->order('cancelled');
        $pending = $this->order('pending');
        $approved = $this->order('approved');

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('procurement.queue.clear'))
            ->assertRedirect(route('procurement.approvals'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('store_orders', ['id' => $declined->id]);
        $this->assertDatabaseMissing('store_orders', ['id' => $cancelled->id]);
        $this->assertDatabaseHas('store_orders', ['id' => $pending->id]);
        $this->assertDatabaseHas('store_orders', ['id' => $approved->id]);
        // The lines go with them rather than being left behind.
        $this->assertDatabaseMissing('store_order_items', ['store_order_id' => $declined->id]);
    }

    public function test_clearing_says_so_when_there_is_nothing_to_clear(): void
    {
        $this->order('pending');

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('procurement.queue.clear'))
            ->assertSessionHas('error');
    }

    public function test_a_non_approver_cannot_clear_anything(): void
    {
        $order = $this->order('declined');

        $this->actingAs(User::factory()->create())
            ->post(route('procurement.queue.clear'))
            ->assertForbidden();

        $this->assertDatabaseHas('store_orders', ['id' => $order->id]);
    }
}
