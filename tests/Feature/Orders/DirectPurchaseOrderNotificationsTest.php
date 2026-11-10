<?php

namespace Tests\Feature\Orders;

use App\Mail\StoreOrderStatusMail;
use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\OrderRequesterNotifier;
use App\Services\StoreOrderAssetProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DirectPurchaseOrderNotificationsTest extends TestCase
{
    private function linked(): array
    {
        Mail::fake();
        Passport::actingAs(User::factory()->superuser()->create());
        $po = PurchaseOrder::factory()->create();
        $request = StoreOrder::create(['user_id' => User::factory()->create()->id, 'status' => 'ordered', 'purchase_order_id' => $po->id]);
        $order = Order::factory()->create(['purchase_order_id' => $po->id]);

        return [$po, $request, $order];
    }

    public function test_the_orderer_is_included_in_vendor_correspondence_recipients(): void
    {
        [$po, $request, $order] = $this->linked();
        $this->assertSame([$request->user->email], $order->storeOrderRequesterEmails());
    }

    public function test_directly_linked_orderer_gets_shipping_once_from_real_shipping_evidence(): void
    {
        [$po, $request, $order] = $this->linked();
        $payload = ['order_number' => $order->order_number, 'purchase_order_number' => $po->po_number,
            'items' => [['description' => 'Sample product', 'quantity' => 2, 'unit_cost' => 50, 'tracking_number' => 'SAMPLE-TRACKING', 'shipped_date' => '2026-11-01']]];
        $this->postJson(route('api.orders.ingest'), $payload)->assertOk()->assertJsonPath('status', 'success');
        $this->postJson(route('api.orders.ingest'), $payload)->assertOk();
        Mail::assertSent(StoreOrderStatusMail::class, fn ($mail) => $mail->event === 'shipped' && $mail->hasTo($request->user->email));
        Mail::assertSent(StoreOrderStatusMail::class, 1);
        $this->assertNotNull($request->fresh()->shipped_at);
    }

    public function test_confirmation_without_shipping_evidence_does_not_send_a_shipping_notice(): void
    {
        [$po, $request, $order] = $this->linked();
        $this->postJson(route('api.orders.ingest'), ['order_number' => $order->order_number, 'purchase_order_number' => $po->po_number,
            'items' => [['description' => 'Sample product', 'quantity' => 2, 'unit_cost' => 50]]])->assertOk();
        Mail::assertNothingSent();
        $this->assertNull($request->fresh()->shipped_at);
    }

    public function test_receiving_all_lines_notifies_the_orderer_once(): void
    {
        [$po, $request, $order] = $this->linked();
        $lines = OrderItem::factory()->count(2)->create(['order_id' => $order->id]);
        $this->postJson(route('api.orders.receive', $order->id), ['items' => [$lines[0]->id]])->assertOk();
        Mail::assertNothingSent();
        $this->postJson(route('api.orders.receive', $order->id), ['items' => [$lines[1]->id]])->assertOk()->assertJsonPath('status', 'success');
        $this->postJson(route('api.orders.receive', $order->id))->assertOk();
        Mail::assertSent(StoreOrderStatusMail::class, fn ($mail) => $mail->event === 'arrived' && $mail->hasTo($request->user->email));
        Mail::assertSent(StoreOrderStatusMail::class, 1);
        $this->assertNotNull($request->fresh()->arrived_at);
    }

    public function test_dedicated_po_preserves_asset_tracking_after_the_vendor_renumbers_the_order(): void
    {
        [$po, $request, $order] = $this->linked();
        $asset = Asset::factory()->create(['order_number' => $order->order_number]);
        $this->assertSame([$asset->id], app(StoreOrderAssetProvisioner::class)->assetsFor($request)->pluck('id')->all());
        StoreOrder::create(['user_id' => User::factory()->create()->id, 'status' => 'ordered', 'purchase_order_id' => $po->id]);
        $this->assertCount(0, app(StoreOrderAssetProvisioner::class)->assetsFor($request));
    }

    public function test_rolled_back_receipt_does_not_notify_the_orderer(): void
    {
        [$po, $request, $order] = $this->linked();
        $line = OrderItem::factory()->create(['order_id' => $order->id]);
        DB::beginTransaction();
        $line->markReceived();
        Mail::assertNothingSent();
        DB::rollBack();
        $this->assertNull($request->fresh()->arrived_at);
        $this->assertNull($line->fresh()->received_at);
        Mail::assertNothingSent();
    }

    public function test_ambiguous_shared_po_does_not_broadcast_direct_notifications(): void
    {
        [$po, $request, $order] = $this->linked();
        StoreOrder::create(['user_id' => User::factory()->create()->id, 'status' => 'ordered', 'purchase_order_id' => $po->id]);
        app(OrderRequesterNotifier::class)->shipped($order, ['tracking' => 'SAMPLE-TRACKING']);
        Mail::assertNothingSent();
        $this->assertNull($request->fresh()->shipped_at);
    }
}
