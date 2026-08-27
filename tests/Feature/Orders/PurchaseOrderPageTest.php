<?php

namespace Tests\Feature\Orders;

/**
 * The purchase order page: the budget, its lines as keyed, and the vendor
 * orders raised under it. The sending itself lives on the order now — see
 * {@see VendorOrderSendTest} — so what this protects is the page's shape and
 * the way it is addressed.
 */
class PurchaseOrderPageTest extends VendorOrderTestCase
{
    public function test_the_purchase_order_page_is_one_screen_and_lists_its_orders()
    {
        $order = $this->vendorOrder();
        $purchaseOrder = $order->purchaseOrder;

        $body = $this->actingAs($this->procurement())
            ->get(route('purchase-orders.show', $purchaseOrder))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.order_lines'))
            ->assertSee('9094662')
            ->assertSee($order->order_number)
            ->assertDontSee(trans('admin/purchase-orders/general.vendor_send_title'))
            ->getContent();

        $this->assertStringContainsString('po-summary', $body, 'the summary should be the two-column block');
        $this->assertStringNotContainsString('general.info', $body, 'no untranslated label should reach the page');
        $this->assertStringNotContainsString('po-documents', $body, 'documents are a section, not a tab');
        $this->assertStringContainsString(trans('admin/lease-schedules/general.documents'), $body);
    }

    /**
     * A purchase order is addressed by its number, because that is what finance,
     * the vendor and every PDF call it. An id still resolves, so older links do
     * not 404.
     */
    public function test_a_purchase_order_is_addressed_by_its_number()
    {
        $purchaseOrder = $this->vendorOrder()->purchaseOrder;

        $this->assertSame('/procurement/purchase-orders/P0026041', route('purchase-orders.show', $purchaseOrder, false));

        $staff = $this->procurement();
        $this->actingAs($staff)->get('/procurement/purchase-orders/P0026041')->assertOk();
        $this->actingAs($staff)->get('/procurement/purchase-orders/'.$purchaseOrder->id)->assertOk();

        $this->actingAs($staff)->get('/purchase-orders/P0026041')->assertRedirect('/procurement/purchase-orders/P0026041');
        $this->actingAs($staff)->get('/requisitions')->assertRedirect('/procurement/requisitions');
        $this->actingAs($staff)->get('/reports/lessor-breakdown')->assertRedirect('/procurement/leasing');
    }

    /**
     * Every procurement report that names a purchase order links to it.
     */
    public function test_reports_link_their_purchase_order_numbers()
    {
        $purchaseOrder = $this->vendorOrder([], [], ['fiscal_year' => 'FY2026-27'])->purchaseOrder;
        $staff = $this->procurement();
        $href = route('purchase-orders.show', $purchaseOrder, false);

        foreach (['reports.procurement.po-budget', 'reports.procurement.po-disposition'] as $report) {
            $body = $this->actingAs($staff)->get(route($report))->assertOk()->getContent();

            $this->assertStringContainsString($href, $body, $report.' should link the purchase order');
            $this->assertStringContainsString('js-lightbox', $body, $report.' should open it in the lightbox');
        }
    }

    /**
     * The order page carries the send: the lines, the account, the loop.
     */
    public function test_the_order_page_carries_the_send()
    {
        $order = $this->vendorOrder(['quote_number' => 'PZKT735']);

        $this->actingAs($this->procurement())
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.vendor_send_title'))
            ->assertSee('9094662')
            ->assertSee('35007722')
            ->assertSee('PZKT735');
    }
}
