<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Snipe announces its own asset writes instead of being polled for them.
 *
 * The observer defers the call; running deferred functions inline lets the
 * test see the request that the finished save would have sent.
 */
class AssetChangeWebhookTest extends TestCase
{
    private const URL = 'https://automations.example.test/api/snipe-asset-changed';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        Http::fake([self::URL => Http::response(['result' => 'synced'], 200)]);
        config()->set('ecu.asset_change_webhook', [
            'url' => self::URL,
            'key' => 'host-key',
            'secret' => 'shared-secret',
            'timeout' => 5,
        ]);
    }

    public function testUpdateAnnouncesTheAssetAndItsChangedColumns()
    {
        $asset = Asset::factory()->laptopMbp()->create();
        Http::fake([self::URL => Http::response(['result' => 'synced'], 200)]);

        $asset->update(['name' => 'renamed']);

        Http::assertSent(function ($request) use ($asset) {
            return $request->url() === self::URL
                && $request->hasHeader('x-functions-key', 'host-key')
                && $request->hasHeader('X-Asset-Change-Secret', 'shared-secret')
                && $request['event'] === 'updated'
                && $request['asset_id'] === $asset->id
                && $request['asset_tag'] === $asset->asset_tag
                && in_array('name', $request['changed'], true)
                && ! in_array('updated_at', $request['changed'], true);
        });
    }

    public function testCreateAndDeleteAreAnnounced()
    {
        $asset = Asset::factory()->laptopMbp()->create();
        Http::assertSent(fn ($request) => $request['event'] === 'created' && $request['asset_id'] === $asset->id);

        $asset->delete();
        Http::assertSent(fn ($request) => $request['event'] === 'deleted' && $request['asset_id'] === $asset->id);
    }

    public function testTouchingOnlyTheTimestampIsNotAnnounced()
    {
        $asset = Asset::factory()->laptopMbp()->create();
        Http::fake([self::URL => Http::response(['result' => 'synced'], 200)]);

        $asset->touch();

        Http::assertNothingSent();
    }

    public function testEmptyUrlTurnsTheNotifierOff()
    {
        config()->set('ecu.asset_change_webhook.url', '');

        Asset::factory()->laptopMbp()->create();

        Http::assertNothingSent();
    }

    public function testAnUnreachableListenerNeverBreaksTheSave()
    {
        Http::fake([self::URL => Http::response('boom', 500)]);

        $asset = Asset::factory()->laptopMbp()->create();
        $asset->update(['name' => 'still saved']);

        $this->assertSame('still saved', $asset->fresh()->name);
    }
}
