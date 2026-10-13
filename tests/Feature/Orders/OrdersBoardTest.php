<?php

namespace Tests\Feature\Orders;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Statuslabel;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use Tests\TestCase;

/**
 * The orders page as a board: each order in flight in exactly one column,
 * and the list kept for history.
 */
class OrdersBoardTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function storeOrder(string $status, array $attributes = []): StoreOrder
    {
        $order = StoreOrder::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'status' => $status,
            'program' => 'faculty',
        ], $attributes));

        StoreOrderItem::create([
            'store_order_id' => $order->id,
            'description' => 'Board test laptop '.$order->id,
            'quantity' => 1,
            'unit_cost' => 1500,
        ]);

        return $order;
    }

    private function order(string $status, array $lines = []): Order
    {
        $order = Order::factory()->create(['status' => $status, 'is_planned' => false]);

        foreach ($lines as $received) {
            OrderItem::create([
                'order_id' => $order->id,
                'description' => 'Board line',
                'quantity' => 1,
                'unit_cost' => 100,
                'received_at' => $received ? now() : null,
            ]);
        }

        return $order;
    }

    /** The markup of one board column, so a card can be placed exactly. */
    private function column(string $html, string $stage): string
    {
        $start = strpos($html, '<div class="pp-col" data-pp-stage="'.$stage.'"');
        $this->assertNotFalse($start, "No {$stage} column on the board.");
        $rest = substr($html, $start + 1);
        $ends = array_filter([
            strpos($rest, '<div class="pp-col" data-pp-stage='),
            strpos($rest, 'id="pp-modal-store"'),
        ], fn ($pos) => $pos !== false);

        return substr($rest, 0, min($ends));
    }

    public function test_each_order_sits_in_its_own_column()
    {
        $pending = $this->storeOrder('pending');
        $unsent = $this->storeOrder('approved');
        $sent = $this->storeOrder('ordered', ['vendor_sent_at' => now()]);
        $ordered = $this->order('ordered', [false, false]);
        $arriving = $this->order('partially_received', [true, false]);
        $done = $this->order('received', [true]);

        $html = $this->actingAs($this->superuser())
            ->get(route('orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('approve-'.$pending->id.'"', $this->column($html, 'approvals'));
        $this->assertStringContainsString('store-'.$unsent->id.'"', $this->column($html, 'vendor'));
        $this->assertStringContainsString(trans('admin/orders/general.board_chip_needs_account'), $this->column($html, 'vendor'));
        $this->assertStringContainsString('store-'.$sent->id.'"', $this->column($html, 'vendor'));
        $this->assertStringContainsString('order-'.$ordered->id.'"', $this->column($html, 'ordered'));
        $this->assertStringContainsString('order-'.$arriving->id.'"', $this->column($html, 'arriving'));
        $this->assertStringNotContainsString('order-'.$arriving->id.'"', $this->column($html, 'ordered'));
        // Finished orders are folded away until asked for.
        $this->assertStringNotContainsString('data-pp-stage="done"', $html);

        $shown = $this->actingAs($this->superuser())
            ->get(route('orders.index', ['done' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('done-'.$done->id.'"', $this->column($shown, 'done'));
    }

    public function test_a_store_order_whose_vendor_order_landed_is_not_shown_twice()
    {
        $po = PurchaseOrder::factory()->create();
        $landed = $this->storeOrder('ordered', ['vendor_sent_at' => now(), 'purchase_order_id' => $po->id]);
        $vendorOrder = Order::factory()->create(['status' => 'ordered', 'is_planned' => false, 'purchase_order_id' => $po->id]);

        $html = $this->actingAs($this->superuser())
            ->get(route('orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('store-'.$landed->id.'"', $html);
        $this->assertStringContainsString('order-'.$vendorOrder->id.'"', $this->column($html, 'ordered'));
    }

    public function test_arrivals_waiting_for_a_request_are_on_the_allocate_column()
    {
        $pending = Statuslabel::factory()->create(['name' => 'Arrived', 'pending' => 1, 'archived' => 0, 'deployable' => 0]);
        $order = $this->order('received');
        $arrival = Asset::factory()->create([
            'model_id' => AssetModel::factory()->create()->id,
            'status_id' => $pending->id,
            'serial' => 'board-serial-one',
            'order_number' => $order->order_number,
            'assigned_to' => null,
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_type' => Asset::class, 'item_id' => $arrival->id, 'quantity' => 1, 'unit_cost' => 100, 'received_at' => now()]);

        $html = $this->actingAs($this->superuser())
            ->get(route('orders.index', ['done' => 1]))
            ->assertOk()
            ->assertSee('board-serial-one')
            ->getContent();

        $this->assertStringContainsString('alloc-'.$order->id.'"', $this->column($html, 'allocate'));
        // Holding hardware nobody has been given, it is not done yet.
        $this->assertStringNotContainsString('done-'.$order->id.'"', $this->column($html, 'done'));
    }

    public function test_approving_from_the_board_returns_to_the_board()
    {
        $order = $this->storeOrder('pending');

        $this->actingAs($this->superuser())
            ->post(route('procurement.queue.decide', $order->id), ['decision' => 'declined', 'return_to' => 'orders'])
            ->assertRedirect(route('orders.index'));

        $this->assertSame('declined', $order->fresh()->status);
    }

    public function test_the_list_view_keeps_filters_search_and_history()
    {
        $match = Order::factory()->create(['order_number' => 'HISTORY-MATCH', 'status' => 'received']);
        Order::factory()->create(['order_number' => 'HISTORY-OTHER', 'status' => 'received']);
        Order::factory()->create(['order_number' => 'CANCELLED-ONE', 'status' => 'cancelled']);

        $this->actingAs($this->superuser())
            ->get(route('orders.index', ['view' => 'list', 'q' => 'MATCH']))
            ->assertOk()
            ->assertSee($match->order_number)
            ->assertDontSee('HISTORY-OTHER');

        $this->actingAs($this->superuser())
            ->get(route('orders.index', ['status' => 'cancelled']))
            ->assertOk()
            ->assertSee('CANCELLED-ONE')
            ->assertDontSee('HISTORY-MATCH');
    }
}
