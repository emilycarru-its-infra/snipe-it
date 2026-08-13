<?php

namespace Tests\Feature\Search;

use App\Models\Contract;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The toolbar lookup reaches the paper trail: a number copied off an order
 * confirmation, an invoice or a lease schedule finds its record.
 */
class TopSearchPaperTrailTest extends TestCase
{
    private function suggest(string $query): array
    {
        return $this->actingAs(User::factory()->superuser()->create())
            ->getJson(route('search.suggest', ['q' => $query]))
            ->assertOk()
            ->json();
    }

    private function groupKeys(array $payload): array
    {
        return array_column($payload['groups'], 'key');
    }

    private function group(array $payload, string $key): ?array
    {
        foreach ($payload['groups'] as $group) {
            if ($group['key'] === $key) {
                return $group;
            }
        }

        return null;
    }

    public function test_an_order_number_finds_its_order(): void
    {
        $order = Order::factory()->create(['order_number' => 'PMZP706']);

        $group = $this->group($this->suggest('PMZP706'), 'orders');

        $this->assertNotNull($group, 'orders must be a search group');
        $this->assertSame('PMZP706', $group['items'][0]['title']);
        $this->assertSame(route('orders.show', $order->id), $group['items'][0]['url']);
    }

    public function test_a_po_number_finds_its_purchase_order(): void
    {
        $po = PurchaseOrder::factory()->create(['po_number' => 'P0025420']);

        $group = $this->group($this->suggest('P0025420'), 'purchase_orders');

        $this->assertNotNull($group);
        $this->assertSame(route('purchase-orders.show', $po->id), $group['items'][0]['url']);
    }

    public function test_an_invoice_number_opens_the_order_that_carries_it(): void
    {
        $order = Order::factory()->create(['order_number' => 'PMZP706']);
        OrderInvoice::create([
            'order_id' => $order->id,
            'invoice_number' => 'AJ7XC8E',
        ]);

        $group = $this->group($this->suggest('AJ7XC8E'), 'invoices');

        $this->assertNotNull($group, 'invoices must be a search group');
        $this->assertSame('AJ7XC8E', $group['items'][0]['title']);
        // Invoices have no page of their own, so the hit opens its parent.
        $this->assertSame(route('orders.show', $order->id), $group['items'][0]['url']);
    }

    public function test_a_lease_schedule_number_finds_its_contract(): void
    {
        $contract = Contract::factory()->create(['name' => 'Devices Leases FY30-31 #3']);
        DB::table('contracts')->where('id', $contract->id)->update(['schedule_number' => '301452-007']);

        $group = $this->group($this->suggest('301452-007'), 'contracts');

        $this->assertNotNull($group);
        $this->assertSame(route('contracts.show', $contract->id), $group['items'][0]['url']);
        $this->assertStringContainsString('301452-007', $group['items'][0]['subtitle']);
    }

    public function test_a_viewer_without_the_orders_grant_sees_none_of_the_paper_trail(): void
    {
        Order::factory()->create(['order_number' => 'PMZP706']);

        $payload = $this->actingAs(User::factory()->create())
            ->getJson(route('search.suggest', ['q' => 'PMZP706']))
            ->assertOk()
            ->json();

        $keys = $this->groupKeys($payload);

        $this->assertNotContains('orders', $keys);
        $this->assertNotContains('purchase_orders', $keys);
        $this->assertNotContains('invoices', $keys);
    }
}
