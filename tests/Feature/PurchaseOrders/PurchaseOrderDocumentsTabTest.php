<?php

namespace Tests\Feature\PurchaseOrders;

use App\Models\PurchaseOrder;
use App\Models\User;
use Tests\TestCase;

class PurchaseOrderDocumentsTabTest extends TestCase
{
    public function test_purchase_order_view_includes_documents_tab()
    {
        $superuser = User::factory()->superuser()->create();
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO-DOCTAB-1']);

        $this->actingAs($superuser)
            ->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('PO-DOCTAB-1')
            ->assertSee('po-documents', false)        // tab pane id
            ->assertSee(trans('admin/lease-schedules/general.documents'));
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
