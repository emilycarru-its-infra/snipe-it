<?php

namespace Tests\Feature\Orders;

use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\User;
use Tests\TestCase;

class OrderInvoicesTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_an_invoice_can_be_added_to_an_order()
    {
        $order = Order::factory()->create(['status' => 'ordered']);

        $this->actingAs($this->superuser())
            ->post(route('orders.invoices.store', $order->id), [
                'invoice_number' => 'AF5MF8A',
                'invoice_date' => '2026-03-01',
                'subtotal' => 1000,
                'tax_gst' => 50,
                'tax_pst' => 70,
                'total' => 1120,
            ])
            ->assertRedirect(route('orders.show', $order->id));

        $this->assertDatabaseHas('order_invoices', [
            'order_id' => $order->id,
            'invoice_number' => 'AF5MF8A',
            'total' => 1120,
        ]);
    }

    public function test_an_invoice_can_be_deleted()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        $invoice = OrderInvoice::factory()->create(['order_id' => $order->id]);

        $this->actingAs($this->superuser())
            ->delete(route('orders.invoices.destroy', ['order' => $order->id, 'invoice' => $invoice->id]))
            ->assertRedirect(route('orders.show', $order->id));

        $this->assertDatabaseMissing('order_invoices', ['id' => $invoice->id]);
    }

    public function test_deleting_an_invoice_releases_its_line_items()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        $invoice = OrderInvoice::factory()->create(['order_id' => $order->id]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
        ]);

        $this->actingAs($this->superuser())
            ->delete(route('orders.invoices.destroy', ['order' => $order->id, 'invoice' => $invoice->id]));

        $this->assertNull($item->fresh()->invoice_id);
    }

    public function test_a_line_item_can_be_billed_to_an_invoice()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        $invoice = OrderInvoice::factory()->create(['order_id' => $order->id]);
        $asset = Asset::factory()->create();

        $this->actingAs($this->superuser())
            ->post(route('orders.items.store', $order->id), [
                'item_type' => 'asset',
                'item_id_asset' => $asset->id,
                'quantity' => 1,
                'unit_cost' => 1000,
                'warranty_cost' => 250,
                'invoice_id' => $invoice->id,
            ])
            ->assertRedirect(route('orders.show', $order->id));

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'item_id' => $asset->id,
            'invoice_id' => $invoice->id,
            'warranty_cost' => 250,
        ]);
    }

    public function test_warranty_cost_contributes_to_the_line_total()
    {
        $item = OrderItem::factory()->create([
            'quantity' => 2,
            'unit_cost' => 1000,
            'warranty_cost' => 250,
        ]);

        $this->assertEquals(2250.0, $item->lineTotal());
    }
}
