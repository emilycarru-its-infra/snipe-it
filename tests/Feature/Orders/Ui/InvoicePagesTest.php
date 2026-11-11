<?php

namespace Tests\Feature\Orders\Ui;

use App\Models\Actionlog;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Tests\TestCase;

/**
 * Every invoice across orders has a list, and each invoice a page of its own
 * addressed by its number.
 */
class InvoicePagesTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function invoice(array $attributes = [], array $order = []): OrderInvoice
    {
        return OrderInvoice::factory()->create($attributes + [
            'order_id' => Order::factory()->create($order + ['order_number' => 'ORD-TEMP-'.uniqid()])->id,
            'invoice_number' => 'INV-'.uniqid(),
            'subtotal' => 100,
            'tax_gst' => 5,
            'tax_pst' => 7,
            'shipping' => 0,
            'total' => 112,
            'approval_status' => 'pending',
        ]);
    }

    public function test_the_list_renders_for_procurement_readers()
    {
        $viewer = User::factory()->create(['permissions' => json_encode(['procurement.view' => '1'])]);

        $this->actingAs($viewer)->get(route('invoices.index'))
            ->assertOk()
            ->assertSee(route('api.order-invoices.index'), false);
    }

    public function test_the_list_and_pages_are_refused_without_procurement_access()
    {
        $invoice = $this->invoice(['invoice_number' => 'INV-1001']);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('invoices.index'))->assertForbidden();
        $this->actingAs($user)->get('/procurement/invoices/'.$invoice->id)->assertForbidden();
        $this->actingAsForApi($user)->getJson(route('api.order-invoices.index'))->assertForbidden();
    }

    public function test_the_list_filters_carry_into_the_table_address()
    {
        $this->actingAs($this->superuser(), 'web')
            ->get(route('invoices.index', ['approval_status' => 'approved', 'q' => 'INV-1001']))
            ->assertOk()
            ->assertSee(e(route('api.order-invoices.index', ['approval_status' => 'approved', 'q' => 'INV-1001'])), false);
    }

    public function test_the_api_list_filters_by_status_type_supplier_date_and_number()
    {
        $supplier = Supplier::factory()->create();
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO-TEST-77']);
        $approved = $this->invoice(['invoice_number' => 'INV-2001', 'approval_status' => 'approved', 'invoice_date' => '2026-02-10'],
            ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id]);
        $credit = $this->invoice(['invoice_number' => 'INV-2002', 'invoice_type' => 'credit', 'invoice_date' => '2026-05-01']);
        $pending = $this->invoice(['invoice_number' => 'INV-2003', 'invoice_date' => '2026-06-01']);
        $admin = $this->superuser();

        $ids = fn (array $query) => collect($this->actingAsForApi($admin)
            ->getJson(route('api.order-invoices.index', $query))->assertOk()->json('rows'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$approved->id], $ids(['approval_status' => 'approved']));
        $this->assertSame([$credit->id], $ids(['invoice_type' => 'credit']));
        $this->assertSame([$approved->id], $ids(['supplier_id' => $supplier->id]));
        $this->assertSame([$credit->id, $pending->id], $ids(['date_from' => '2026-04-01', 'date_to' => '2026-12-31']));
        $this->assertSame([$approved->id], $ids(['q' => 'PO-TEST-77']));
        $this->assertSame([$pending->id], $ids(['search' => 'INV-2003']));

        $row = $this->actingAsForApi($admin)->getJson(route('api.order-invoices.index', ['q' => 'INV-2001']))->json('rows.0');
        $this->assertSame('INV-2001', $row['invoice_number']);
        $this->assertSame('PO-TEST-77', $row['purchase_order']['po_number']);
        $this->assertSame(route('invoices.show', $approved), $row['url']);
    }

    public function test_an_invoice_page_opens_by_number_and_an_id_link_forwards_to_it()
    {
        $invoice = $this->invoice(['invoice_number' => 'INV-1001', 'notes' => 'Checked against packing slip.']);
        OrderItem::factory()->create(['order_id' => $invoice->order_id, 'invoice_id' => $invoice->id, 'quantity' => 2, 'unit_cost' => 50, 'description' => 'Docking station']);
        $admin = $this->superuser();

        $this->assertStringEndsWith('/procurement/invoices/INV-1001', route('invoices.show', $invoice));
        $this->actingAs($admin, 'web')->get('/procurement/invoices/'.$invoice->id)
            ->assertRedirect(route('invoices.show', $invoice));
        $this->actingAs($admin, 'web')->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('INV-1001')
            ->assertSee('Docking station')
            ->assertSee('Checked against packing slip.')
            ->assertSee(trans('admin/purchase-orders/general.invoice_page_lines_match'))
            ->assertSee(trans('admin/purchase-orders/general.invoice_page_payment_none'));
    }

    public function test_an_invoice_number_two_suppliers_share_is_addressed_by_id()
    {
        $first = $this->invoice(['invoice_number' => 'INV-SHARED']);
        $this->invoice(['invoice_number' => 'INV-SHARED']);

        $this->assertStringEndsWith('/procurement/invoices/'.$first->id, route('invoices.show', $first));
        $this->actingAs($this->superuser(), 'web')->get(route('invoices.show', $first))->assertOk();
    }

    public function test_the_page_flags_lines_and_payments_that_do_not_add_up()
    {
        $invoice = $this->invoice(['invoice_number' => 'INV-1002']);
        OrderItem::factory()->create(['order_id' => $invoice->order_id, 'invoice_id' => $invoice->id, 'quantity' => 1, 'unit_cost' => 90]);
        $invoice->forceFill(['ap_status' => 'paid', 'ap_paid_amount' => 100, 'ap_paid_at' => '2026-03-01', 'ap_voucher_number' => 'VCH-1'])->save();

        $this->actingAs($this->superuser(), 'web')->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('VCH-1')
            ->assertSee(trans('admin/purchase-orders/general.ap_status_paid'))
            ->assertDontSee(trans('admin/purchase-orders/general.invoice_page_lines_match'))
            ->assertSeeText('apart.');
    }

    public function test_the_page_shows_the_history_and_approval_returns_to_it()
    {
        $invoice = $this->invoice(['invoice_number' => 'INV-1003', 'invoice_date' => '2026-01-05']);
        $admin = $this->superuser();

        $this->actingAs($admin, 'web')
            ->patch(route('reports.procurement.invoice-approval.update', $invoice->id), [
                'approval_status' => 'disputed',
                'return_to' => 'invoice',
            ])
            ->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame('disputed', $invoice->refresh()->approval_status);
        $this->assertTrue(Actionlog::where('item_type', OrderInvoice::class)->where('item_id', $invoice->id)->exists());
        $this->actingAs($admin, 'web')->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.invoice_approval_disputed'));
    }

    public function test_the_order_page_and_reports_link_to_the_invoice_page()
    {
        $invoice = $this->invoice(['invoice_number' => 'INV-1004']);
        $admin = $this->superuser();
        $url = route('invoices.show', $invoice);

        $this->actingAs($admin, 'web')->get(route('orders.show', $invoice->order))->assertOk()->assertSee($url, false);
        $this->actingAs($admin, 'web')->get(route('reports.procurement.invoices', ['fiscal_year' => 'all']))->assertOk()->assertSee($url, false);
        $this->actingAs($admin, 'web')->get(route('reports.procurement.invoice-approval', ['fiscal_year' => 'all']))->assertOk()->assertSee($url, false);
        $this->actingAs($admin, 'web')->get(route('invoices.index'))->assertOk()->assertSee(route('invoices.index'), false);
    }
}
