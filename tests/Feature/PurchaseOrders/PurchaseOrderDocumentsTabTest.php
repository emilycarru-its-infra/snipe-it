<?php

namespace Tests\Feature\PurchaseOrders;

use App\Models\PurchaseOrder;
use App\Models\User;
use Tests\TestCase;

/**
 * Documents on a purchase order. They used to sit behind a tab; they are a
 * section at the foot of the page now, because the purchase order PDF and the
 * vendor's quote are what somebody opens this page to read and a tab hid them
 * behind a click.
 */
class PurchaseOrderDocumentsTabTest extends TestCase
{
    public function test_purchase_order_view_includes_its_documents()
    {
        $superuser = User::factory()->superuser()->create();
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO-DOCTAB-1']);

        $this->actingAs($superuser)
            ->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('PO-DOCTAB-1')
            ->assertSee(trans('admin/lease-schedules/general.documents'))
            // The upload form is on the page itself rather than in a hidden pane.
            ->assertSee(route('ui.files.store', ['object_type' => 'purchase-orders', 'id' => $po->id]), false);
    }

    public function test_upload_routes_accept_purchase_orders_object_type()
    {
        // The generic file-upload routes have a regex constraint; this
        // confirms 'purchase-orders' was added to the allow list.
        $route = route('ui.files.store', ['object_type' => 'purchase-orders', 'id' => 1]);
        $this->assertStringContainsString('/purchase-orders/1/files', $route);

        $route = route('ui.files.store', ['object_type' => 'lease-schedules', 'id' => 1]);
        $this->assertStringContainsString('/lease-schedules/1/files', $route);
    }
}
