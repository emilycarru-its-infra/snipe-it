<?php

namespace Tests\Feature\Orders;

use App\Models\Actionlog;
use App\Models\CatalogItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The crossing from requisition to purchase order — the point where an order
 * starts counting against a budget, and therefore the point that has to be
 * gated on the document that authorised it.
 */
class RequisitionPromotionTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function requisition(array $overrides = []): Requisition
    {
        $item = CatalogItem::create([
            'name' => 'MacBook Pro | 16" | M5 Max',
            'category' => 'Laptops',
            'vendor_sku' => '9219355',
            'unit_cost' => 1000,
            'price_type' => 'quoted',
        ]);

        $requisition = Requisition::create(array_merge([
            'title' => 'Faculty refresh — Design, Fall 2026',
            'status' => 'requisitioned',
            'requisition_number' => 'REQM0012345',
            'fiscal_year' => 'FY2026-27',
            'cost_center' => '61200',
            'gst_rate' => 0.05,
            'pst_rate' => 0.07,
            'shipping' => 0,
        ], $overrides));

        RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'catalog_item_id' => $item->id,
            'description' => $item->name,
            'vendor_sku' => $item->vendor_sku,
            'quantity' => 2,
            'unit_cost' => 1000,
            'pst_applicable' => true,
        ]);

        return $requisition->fresh(['items']);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('P0025747.pdf', 64, 'application/pdf');
    }

    public function test_promotion_creates_a_purchase_order_carrying_the_requisition_fields()
    {
        Storage::fake();

        $supplier = Supplier::factory()->create();
        $requisition = $this->requisition(['supplier_id' => $supplier->id]);

        $this->actingAs($this->superuser())
            ->post(route('requisitions.promote', $requisition->id), [
                'po_number' => 'P0025747',
                'document' => $this->pdf(),
            ])
            ->assertRedirect();

        $purchaseOrder = PurchaseOrder::where('po_number', 'P0025747')->first();

        $this->assertNotNull($purchaseOrder);
        $this->assertSame('FY2026-27', $purchaseOrder->fiscal_year);
        $this->assertSame('61200', $purchaseOrder->cost_center);
        $this->assertSame($supplier->id, $purchaseOrder->supplier_id);
        $this->assertSame('open', $purchaseOrder->status);

        // Budget defaults to the requisition total: 2000 + 5% GST + 7% PST.
        $this->assertEqualsWithDelta(2240.00, (float) $purchaseOrder->budget, 0.001);

        $requisition->refresh();
        $this->assertSame('ordered', $requisition->status);
        $this->assertSame($purchaseOrder->id, $requisition->purchase_order_id);
        $this->assertSame('P0025747', $requisition->display_name);
    }

    public function test_the_pdf_is_filed_against_the_purchase_order()
    {
        Storage::fake();

        $requisition = $this->requisition();

        $this->actingAs($this->superuser())
            ->post(route('requisitions.promote', $requisition->id), [
                'po_number' => 'P0025747',
                'document' => $this->pdf(),
            ])
            ->assertRedirect();

        $purchaseOrder = PurchaseOrder::where('po_number', 'P0025747')->first();

        $log = Actionlog::where('item_type', PurchaseOrder::class)
            ->where('item_id', $purchaseOrder->id)
            ->where('action_type', 'uploaded')
            ->first();

        $this->assertNotNull($log, 'the PO document should be logged like any other attachment');
        $this->assertStringStartsWith('po-'.$purchaseOrder->id.'-', $log->filename);
        Storage::assertExists('private_uploads/purchase-orders/'.$log->filename);
    }

    public function test_promotion_without_a_pdf_is_rejected_and_creates_nothing()
    {
        Storage::fake();

        $requisition = $this->requisition();

        $this->actingAs($this->superuser())
            ->post(route('requisitions.promote', $requisition->id), ['po_number' => 'P0025747'])
            ->assertSessionHasErrors('document');

        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame('requisitioned', $requisition->refresh()->status);
        $this->assertNull($requisition->purchase_order_id);
    }

    public function test_a_non_pdf_attachment_is_rejected()
    {
        Storage::fake();

        $requisition = $this->requisition();

        $this->actingAs($this->superuser())
            ->post(route('requisitions.promote', $requisition->id), [
                'po_number' => 'P0025747',
                'document' => UploadedFile::fake()->image('screenshot.png'),
            ])
            ->assertSessionHasErrors('document');

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_an_existing_purchase_order_can_be_linked_instead_of_created()
    {
        Storage::fake();

        $existing = PurchaseOrder::create([
            'po_number' => 'P0025747',
            'status' => 'open',
            'budget' => 9999,
        ]);
        $requisition = $this->requisition();

        $this->actingAs($this->superuser())
            ->post(route('requisitions.promote', $requisition->id), [
                'purchase_order_id' => $existing->id,
                'document' => $this->pdf(),
            ])
            ->assertRedirect();

        $requisition->refresh();

        $this->assertSame(1, PurchaseOrder::count(), 'linking must not mint a duplicate ledger row');
        $this->assertSame($existing->id, $requisition->purchase_order_id);
        $this->assertSame('ordered', $requisition->status);
        // The existing row keeps its own budget rather than being overwritten.
        $this->assertEqualsWithDelta(9999.00, (float) $existing->refresh()->budget, 0.001);
    }

    public function test_linking_a_purchase_order_that_already_has_a_document_does_not_ask_for_another()
    {
        Storage::fake();

        $existing = PurchaseOrder::create(['po_number' => 'P0025747', 'status' => 'open']);
        $existing->logUpload('po-existing.pdf', 'Filed earlier');

        $requisition = $this->requisition();

        $this->actingAs($this->superuser())
            ->post(route('requisitions.promote', $requisition->id), [
                'purchase_order_id' => $existing->id,
            ])
            ->assertRedirect();

        $this->assertSame('ordered', $requisition->refresh()->status);
    }

    public function test_a_requisition_cannot_be_promoted_twice()
    {
        Storage::fake();

        $requisition = $this->requisition();
        $user = $this->superuser();

        $this->actingAs($user)->post(route('requisitions.promote', $requisition->id), [
            'po_number' => 'P0025747',
            'document' => $this->pdf(),
        ])->assertRedirect();

        $this->actingAs($user)->post(route('requisitions.promote', $requisition->id), [
            'po_number' => 'P0099999',
            'document' => $this->pdf(),
        ])->assertRedirect();

        $this->assertSame(1, PurchaseOrder::count());
        $this->assertNull(PurchaseOrder::where('po_number', 'P0099999')->first());
    }

    public function test_a_requisition_with_no_lines_cannot_be_promoted()
    {
        Storage::fake();

        $requisition = Requisition::create(['title' => 'Empty', 'status' => 'requisitioned']);

        $this->actingAs($this->superuser())
            ->post(route('requisitions.promote', $requisition->id), [
                'po_number' => 'P0025747',
                'document' => $this->pdf(),
            ])
            ->assertRedirect();

        $this->assertSame(0, PurchaseOrder::count());
    }
}
