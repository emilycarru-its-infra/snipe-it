<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProcurementObservationsTest extends TestCase
{
    private const FAMILIES = ['purchase-orders', 'orders', 'order-lines', 'order-invoices'];

    private function url(string $family): string
    {
        return '/api/v1/workflow-observations/'.$family;
    }

    private function reader(array $fields = []): User
    {
        return User::factory()->create(array_merge(['permissions' => '{"orders.view":"1"}'], $fields));
    }

    public function test_each_collection_requires_the_existing_order_read_permission(): void
    {
        $unprivileged = User::factory()->create();
        foreach (self::FAMILIES as $family) {
            $this->getJson($this->url($family))->assertUnauthorized();
        }
        $this->actingAsForApi($unprivileged);
        foreach (self::FAMILIES as $family) {
            $this->getJson($this->url($family))->assertForbidden();
        }
        $this->actingAsForApi($this->reader());
        foreach (self::FAMILIES as $family) {
            $this->getJson($this->url($family))->assertOk()->assertExactJson(['total' => 0, 'rows' => []]);
        }
    }

    public function test_stored_amounts_nulls_and_parent_links_are_not_formatted_or_expanded(): void
    {
        Mail::fake();
        $person = User::factory()->create(['email' => 'excluded-person@example.test']);
        $supplier = Supplier::factory()->create(['name' => 'Excluded supplier label', 'email' => 'excluded-supplier@example.test']);
        $po = PurchaseOrder::factory()->create(['supplier_id' => $supplier->id, 'budget' => '123456789.1234', 'title' => 'Example & retained budget', 'notes' => 'Excluded PO note']);
        $split = PurchaseOrder::factory()->create();
        $order = Order::factory()->create(['purchase_order_id' => $po->id, 'supplier_id' => $supplier->id, 'created_by' => $person->id, 'order_cost' => '12345.6789', 'notes' => 'Excluded order note', 'is_planned' => false]);
        $invoice = OrderInvoice::factory()->create(['order_id' => $order->id, 'purchase_order_id' => $split->id, 'subtotal' => '12345.6789', 'tax_gst' => null, 'approval_status' => 'approved', 'approved_by' => $person->id, 'approved_at' => '2026-10-01 12:34:56', 'notes' => 'Excluded invoice note']);
        $line = OrderItem::factory()->create(['order_id' => $order->id, 'purchase_order_id' => $split->id, 'invoice_id' => $invoice->id, 'description' => 'Example & retained line', 'unit_cost' => '41.2051', 'warranty_cost' => null, 'quantity' => 3]);
        $before = [$po->fresh()->getRawOriginal(), $order->fresh()->getRawOriginal(), $invoice->fresh()->getRawOriginal(), $line->fresh()->getRawOriginal()];
        $this->actingAsForApi($this->reader());
        $responses = [];
        foreach (self::FAMILIES as $family) {
            $responses[$family] = $this->getJson($this->url($family))->assertOk();
            $this->assertTrue($responses[$family]->headers->hasCacheControlDirective('no-store'));
            foreach (['excluded-person@example.test', 'excluded-supplier@example.test', 'Excluded supplier label', 'Excluded PO note', 'Excluded order note', 'Excluded invoice note'] as $excluded) {
                $this->assertStringNotContainsString($excluded, $responses[$family]->getContent());
            }
            $this->assertArrayNotHasKey('currency', $responses[$family]->json('rows.0'));
            $this->assertArrayNotHasKey('created_by', $responses[$family]->json('rows.0'));
            $this->assertArrayNotHasKey('approved_by', $responses[$family]->json('rows.0'));
        }
        $responses['purchase-orders']->assertJsonPath('rows.0.budget', '123456789.1234')->assertJsonPath('rows.0.title', 'Example & retained budget');
        $responses['orders']->assertJsonPath('rows.0.observed_line_count', '1')->assertJsonPath('rows.0.observed_invoice_count', '1')->assertJsonPath('rows.0.order_cost', '12345.6789')->assertJsonPath('rows.0.purchase_order_id', (string) $po->id)->assertJsonPath('rows.0.supplier_id', (string) $supplier->id)->assertJsonPath('rows.0.is_planned', '0');
        $responses['order-lines']->assertJsonPath('rows.0.purchase_order_id', (string) $split->id)->assertJsonPath('rows.0.invoice_id', (string) $invoice->id)->assertJsonPath('rows.0.unit_cost', '41.2051')->assertJsonPath('rows.0.warranty_cost', null)->assertJsonPath('rows.0.description', 'Example & retained line');
        $responses['order-invoices']->assertJsonPath('rows.0.subtotal', '12345.6789')->assertJsonPath('rows.0.tax_gst', null)->assertJsonPath('rows.0.approval_status', 'approved')->assertJsonPath('rows.0.approved_at', '2026-10-01 12:34:56');
        $this->assertSame($before, [$po->fresh()->getRawOriginal(), $order->fresh()->getRawOriginal(), $invoice->fresh()->getRawOriginal(), $line->fresh()->getRawOriginal()]);
        Mail::assertNothingSent();
    }

    public function test_children_require_visible_live_parents_and_preserve_company_scope(): void
    {
        $this->settings->enableMultipleFullCompanySupport();
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $po = PurchaseOrder::factory()->create(['company_id' => $own->id]);
        $foreign = PurchaseOrder::factory()->create(['company_id' => $other->id]);
        $deleted = PurchaseOrder::factory()->create(['company_id' => $own->id]);
        $deleted->delete();
        $order = Order::factory()->create(['company_id' => $own->id, 'purchase_order_id' => $po->id]);
        $outside = Order::factory()->create(['company_id' => $other->id, 'purchase_order_id' => $foreign->id]);
        $gone = Order::factory()->create(['company_id' => $own->id, 'purchase_order_id' => $po->id]);
        $gone->delete();
        $badParent = Order::factory()->create(['company_id' => $own->id, 'purchase_order_id' => $deleted->id]);
        $crossParent = Order::factory()->create(['company_id' => $own->id, 'purchase_order_id' => $foreign->id]);
        $invoice = OrderInvoice::factory()->create(['order_id' => $order->id, 'purchase_order_id' => $po->id]);
        $line = OrderItem::factory()->create(['order_id' => $order->id, 'purchase_order_id' => $po->id, 'invoice_id' => $invoice->id]);
        foreach ([$outside, $gone, $badParent, $crossParent] as $bad) {
            OrderItem::factory()->create(['order_id' => $bad->id]);
            OrderInvoice::factory()->create(['order_id' => $bad->id]);
        }
        OrderItem::factory()->create(['order_id' => $order->id, 'purchase_order_id' => $foreign->id]);
        OrderInvoice::factory()->create(['order_id' => $order->id, 'purchase_order_id' => $foreign->id]);
        // Orphan children and an invoice from another order are outside the linked scope.
        DB::table('order_items')->insert(['order_id' => 999999, 'description' => 'Example orphan', 'quantity' => 1]);
        $otherInvoice = OrderInvoice::factory()->create(['order_id' => $outside->id]);
        OrderItem::factory()->create(['order_id' => $order->id, 'invoice_id' => $otherInvoice->id]);
        $this->actingAsForApi($this->reader(['company_id' => $own->id]));
        foreach (['purchase-orders' => $po, 'orders' => $order, 'order-lines' => $line, 'order-invoices' => $invoice] as $family => $kept) {
            $this->getJson($this->url($family))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('rows.0.id', (string) $kept->id);
        }
    }

    public function test_order_coverage_counts_expose_filtered_child_gaps_without_child_details(): void
    {
        $this->settings->enableMultipleFullCompanySupport();
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $order = Order::factory()->create(['company_id' => $own->id]);
        $foreign = PurchaseOrder::factory()->create(['company_id' => $other->id]);
        OrderItem::factory()->create(['order_id' => $order->id]);
        OrderItem::factory()->create(['order_id' => $order->id, 'purchase_order_id' => $foreign->id]);
        OrderInvoice::factory()->create(['order_id' => $order->id]);
        OrderInvoice::factory()->create(['order_id' => $order->id, 'purchase_order_id' => $foreign->id]);
        $this->actingAsForApi($this->reader(['company_id' => $own->id]));
        $this->getJson($this->url('orders'))->assertOk()->assertJsonPath('rows.0.observed_line_count', '2')->assertJsonPath('rows.0.observed_invoice_count', '2');
        $this->getJson($this->url('order-lines'))->assertOk()->assertJsonPath('total', 1);
        $this->getJson($this->url('order-invoices'))->assertOk()->assertJsonPath('total', 1);
    }

    public function test_fixed_paging_is_bounded_and_collections_are_read_only(): void
    {
        $first = Order::factory()->create(['order_number' => 'EXAMPLE-Z']);
        $second = Order::factory()->create(['order_number' => 'EXAMPLE-A']);
        $this->actingAsForApi($this->reader());
        $this->getJson($this->url('orders').'?limit=1&offset=1&sort=order_number&order=desc')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('rows.0.id', (string) $second->id);
        $this->getJson($this->url('orders').'?limit=1')->assertOk()->assertJsonPath('rows.0.id', (string) $first->id);
        foreach (self::FAMILIES as $family) {
            foreach (['limit=0', 'limit=101', 'offset=-1', 'offset=50001', 'offset=1.5'] as $bad) {
                $this->getJson($this->url($family).'?'.$bad)->assertUnprocessable();
            }
            $this->getJson($this->url($family).'?offset=50000')->assertOk();
            $this->postJson($this->url($family), [])->assertMethodNotAllowed();
        }
    }
}
