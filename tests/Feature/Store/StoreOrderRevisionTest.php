<?php

namespace Tests\Feature\Store;

use App\Http\Transformers\ActionlogsTransformer;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Services\StoreOrderRevision;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

class StoreOrderRevisionTest extends TestCase
{
    private function request(): array
    {
        Mail::fake();
        $requester = User::factory()->create();
        $actor = User::factory()->superuser()->create();
        Passport::actingAs($actor);
        $model = AssetModel::factory()->create();
        $catalog = CatalogItem::create(['name' => 'Sample display', 'model_id' => $model->id, 'unit_cost' => 500, 'price_type' => 'estimate', 'category' => 'Displays', 'product_type' => 'standard']);
        $order = StoreOrder::create(['user_id' => $requester->id, 'status' => 'ordered', 'vendor_sent_at' => now(), 'funding_account' => 'purchase_admin']);
        $line = StoreOrderItem::create(['store_order_id' => $order->id, 'catalog_item_id' => $catalog->id, 'description' => $catalog->name, 'quantity' => 3, 'unit_cost' => 500]);
        $status = Statuslabel::factory()->create(['name' => 'New (Ordered)', 'pending' => 1, 'deployable' => 0]);
        $assets = Asset::factory()->count(3)->create(['model_id' => $model->id, 'order_number' => $order->reference(), 'status_id' => $status->id, 'assigned_to' => null, 'serial' => '']);
        $data = ['revision' => StoreOrderRevision::fingerprint($order->fresh('items')), 'reason' => 'Approved paperwork reduces the requested quantity.', 'decision_notes' => 'Two displays approved; third deferred.', 'items' => [['catalog_item_id' => $catalog->id, 'quantity' => 2, 'unit_cost' => 450]]];

        return [$order, $line, $assets, $data, $actor];
    }

    public function test_revision_preserves_owner_and_asset_identity_and_logs_the_released_surplus(): void
    {
        [$order, $line, $assets, $data, $actor] = $this->request();
        $response = $this->patchJson(route('api.store-orders.revise', $order), $data)->assertOk()->assertJsonPath('status', 'success');
        $this->assertSame($order->user_id, $response->json('payload.order.requester.id'));
        $this->assertSame([$assets->last()->id], $response->json('payload.released_asset_ids'));
        $this->assertSoftDeleted($assets->last());
        $this->assertNotSoftDeleted($assets->first());
        $this->assertSame(2, $line->fresh()->quantity);
        $this->assertEquals(450, $line->fresh()->unit_cost);
        $this->assertNull($order->fresh()->confirmed_at);
        $log = Actionlog::where('item_type', StoreOrder::class)->where('item_id', $order->id)->firstOrFail();
        $this->assertSame($actor->id, $log->created_by);
        $this->assertSame(3, json_decode($log->log_meta, true)['items']['old'][0]['quantity']);
        $history = (new ActionlogsTransformer)->transformActionlog($log, Setting::getSettings());
        $this->assertSame(3, json_decode(html_entity_decode($history['log_meta']['items']['old']), true)[0]['quantity']);
        Mail::assertNothingSent();
        $this->patchJson(route('api.store-orders.revise', $order), $data)->assertJsonPath('status', 'error');
        $this->assertSame(2, Asset::where('order_number', $order->reference())->count());
    }

    public function test_stale_revision_and_increased_quantity_are_refused_without_changes(): void
    {
        [$order, $line, $assets, $data] = $this->request();
        $stale = $data;
        $stale['revision'] = str_repeat('0', 64);
        $this->patchJson(route('api.store-orders.revise', $order), $stale)->assertJsonPath('status', 'error');
        $data['items'][0]['quantity'] = 4;
        $this->patchJson(route('api.store-orders.revise', $order), $data)->assertJsonPath('status', 'error');
        $this->assertSame(3, $line->fresh()->quantity);
        $this->assertSame(3, Asset::where('order_number', $order->reference())->count());
        Mail::assertNothingSent();
    }

    public function test_a_fulfilled_surplus_is_protected_and_the_whole_revision_is_rolled_back(): void
    {
        [$order, $line, $assets, $data] = $this->request();
        $assets->last()->update(['serial' => 'SAMPLE-RECEIVED-UNIT']);
        $this->patchJson(route('api.store-orders.revise', $order), $data)->assertJsonPath('status', 'error');
        $this->assertSame(3, $line->fresh()->quantity);
        $this->assertEquals(500, $line->fresh()->unit_cost);
        $this->assertNotSoftDeleted($assets->last());
        $this->assertNull($order->fresh()->decision_notes);
    }

    public function test_purchase_confirmation_closes_the_revision_path(): void
    {
        [$order, $line, $assets, $data] = $this->request();
        $order->update(['confirmed_at' => now()]);
        $data['revision'] = StoreOrderRevision::fingerprint($order->fresh('items'));
        $this->patchJson(route('api.store-orders.revise', $order), $data)->assertJsonPath('status', 'error');
        $this->assertSame(3, $line->fresh()->quantity);
    }

    public function test_a_requester_cannot_use_the_procurement_revision_command(): void
    {
        [$order, $line, $assets, $data] = $this->request();
        Passport::actingAs($order->user);
        $this->patchJson(route('api.store-orders.revise', $order), $data)->assertForbidden();
        $this->assertSame(3, $line->fresh()->quantity);
    }
}
