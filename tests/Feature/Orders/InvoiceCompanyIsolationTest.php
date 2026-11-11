<?php

namespace Tests\Feature\Orders;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use Tests\TestCase;

/**
 * With full multiple company support, an invoice is seen only by the company
 * that owns its order — or, with no order, its purchase order.
 */
class InvoiceCompanyIsolationTest extends TestCase
{
    private Company $own;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->enableMultipleFullCompanySupport();
        $this->own = Company::factory()->create();
        $this->other = Company::factory()->create();
    }

    private function editor(Company $company): User
    {
        return User::factory()->create([
            'company_id' => $company->id,
            'permissions' => json_encode(['orders.view' => '1', 'orders.edit' => '1', 'procurement.edit' => '1']),
        ]);
    }

    /** @return array<string, OrderInvoice> */
    private function foreignInvoices(): array
    {
        $po = PurchaseOrder::factory()->create(['company_id' => $this->other->id]);

        return [
            'order-backed' => OrderInvoice::factory()->create([
                'order_id' => Order::factory()->create(['company_id' => $this->other->id])->id,
                'invoice_number' => 'INV-FOREIGN-1',
            ]),
            // Its order record is gone, so the purchase order decides.
            'po-only' => OrderInvoice::factory()->create([
                'order_id' => 999999,
                'purchase_order_id' => $po->id,
                'invoice_number' => 'INV-FOREIGN-2',
            ]),
        ];
    }

    public function test_another_companys_invoices_are_not_listed_shown_searched_or_paid()
    {
        $invoices = $this->foreignInvoices();
        $mine = OrderInvoice::factory()->create([
            'order_id' => Order::factory()->create(['company_id' => $this->own->id])->id,
            'invoice_number' => 'INV-OWN-1',
        ]);
        $user = $this->editor($this->own);

        $rows = $this->actingAsForApi($user)->getJson(route('api.order-invoices.index'))->assertOk()->json('rows');
        $this->assertSame([$mine->id], array_column($rows, 'id'));

        foreach ($invoices as $case => $invoice) {
            $this->actingAs($user, 'web')->get('/procurement/invoices/'.$invoice->id)
                ->assertNotFound();

            $this->actingAsForApi($user)
                ->patchJson(route('api.order-invoices.payment', $invoice->id), ['status' => 'paid'])
                ->assertNotFound();
            $this->assertNull($invoice->refresh()->ap_status, $case);

            $this->actingAs($user, 'web')->getJson(route('search.suggest', ['q' => $invoice->invoice_number]))
                ->assertOk()
                ->assertJsonPath('total', 0);
        }

        $this->actingAs($user, 'web')->getJson(route('search.suggest', ['q' => 'INV-OWN-1']))
            ->assertOk()
            ->assertJsonPath('groups.0.items.0.title', 'INV-OWN-1');
    }

    public function test_the_owning_company_sees_a_po_only_invoice()
    {
        $po = PurchaseOrder::factory()->create(['company_id' => $this->own->id]);
        $invoice = OrderInvoice::factory()->create(['order_id' => 999998, 'purchase_order_id' => $po->id, 'invoice_number' => 'INV-OWN-PO']);
        $user = $this->editor($this->own);

        $this->actingAsForApi($user)->getJson(route('api.order-invoices.index'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.id', $invoice->id);
        $this->actingAs($user, 'web')->get(route('invoices.show', $invoice))->assertOk()->assertSee('INV-OWN-PO');
        $this->actingAsForApi($user)
            ->patchJson(route('api.order-invoices.payment', $invoice->id), ['status' => 'entered'])
            ->assertOk();
    }

    public function test_an_invoice_with_neither_parent_is_left_to_superusers()
    {
        $invoice = OrderInvoice::factory()->create(['order_id' => 999997, 'purchase_order_id' => null, 'invoice_number' => 'INV-ORPHAN']);

        $this->actingAsForApi($this->editor($this->own))->getJson(route('api.order-invoices.index'))
            ->assertOk()
            ->assertJsonPath('total', 0);
        $this->actingAs($this->editor($this->own), 'web')->get('/procurement/invoices/'.$invoice->id)->assertNotFound();
        $this->actingAs(User::factory()->superuser()->create(), 'web')->get(route('invoices.show', $invoice))->assertOk();
    }
}
