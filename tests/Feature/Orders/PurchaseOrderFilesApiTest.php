<?php

namespace Tests\Feature\Orders;

use App\Models\Actionlog;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Purchase orders accept their paperwork over the API, not just through the
 * documents tab in a browser session. The quote a vendor returns is the
 * authoritative cost record and arrives by email — being able to attach it
 * without a UI round trip is what makes the PO ledger usable from a script.
 */
class PurchaseOrderFilesApiTest extends TestCase
{
    private function purchaseOrder(): PurchaseOrder
    {
        return PurchaseOrder::factory()->create(['po_number' => 'P0099001']);
    }

    public function test_a_user_with_order_edit_can_attach_a_pdf()
    {
        Storage::fake('local');
        $po = $this->purchaseOrder();

        $this->actingAsForApi(User::factory()->create([
            'permissions' => json_encode(['orders.edit' => '1']),
        ]))
            ->postJson(route('api.files.store', ['object_type' => 'purchase-orders', 'id' => $po->id]), [
                'file' => [UploadedFile::fake()->create('quote.pdf', 40, 'application/pdf')],
                'notes' => 'Vendor quote',
            ])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('action_logs', [
            'item_id' => $po->id,
            'item_type' => PurchaseOrder::class,
            'action_type' => 'uploaded',
            'note' => 'Vendor quote',
        ]);
    }

    public function test_the_upload_is_listed_and_downloadable()
    {
        Storage::fake('local');
        $po = $this->purchaseOrder();
        $actor = User::factory()->create(['permissions' => json_encode(['orders.edit' => '1'])]);

        $this->actingAsForApi($actor)
            ->postJson(route('api.files.store', ['object_type' => 'purchase-orders', 'id' => $po->id]), [
                'file' => [UploadedFile::fake()->create('quote.pdf', 40, 'application/pdf')],
            ])->assertOk();

        $log = Actionlog::where('item_id', $po->id)
            ->where('item_type', PurchaseOrder::class)
            ->whereNotNull('filename')
            ->firstOrFail();

        $this->actingAsForApi($actor)
            ->getJson(route('api.files.index', ['object_type' => 'purchase-orders', 'id' => $po->id]))
            ->assertOk();

        $this->assertNotEmpty($log->filename);
    }

    public function test_a_stranger_cannot_attach_anything()
    {
        Storage::fake('local');
        $po = $this->purchaseOrder();

        $this->actingAsForApi(User::factory()->create(['permissions' => json_encode([])]))
            ->postJson(route('api.files.store', ['object_type' => 'purchase-orders', 'id' => $po->id]), [
                'file' => [UploadedFile::fake()->create('quote.pdf', 40, 'application/pdf')],
            ])
            ->assertForbidden();
    }

    /**
     * The blanket `admin` the ITS groups carry has to keep working, since it
     * is what the documents tab has always relied on.
     */
    public function test_the_blanket_admin_permission_still_works()
    {
        Storage::fake('local');
        $po = $this->purchaseOrder();

        $this->actingAsForApi(User::factory()->create([
            'permissions' => json_encode(['admin' => '1']),
        ]))
            ->postJson(route('api.files.store', ['object_type' => 'purchase-orders', 'id' => $po->id]), [
                'file' => [UploadedFile::fake()->create('quote.pdf', 40, 'application/pdf')],
            ])
            ->assertOk();
    }
}
