<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The observer defers its webhook, and deferred callbacks only run over HTTP
 * when InvokeDeferredCallbacks is in the kernel's global middleware. This
 * test deliberately does NOT call withoutDefer(): it drives a real API
 * request through the kernel, including terminate(), and asserts the
 * webhook actually left. If the middleware is ever dropped again this is
 * the test that fails.
 */
class AssetChangeWebhookOverHttpTest extends TestCase
{
    private const URL = 'https://automations.example.test/api/snipe-asset-changed';

    public function testApiUpdateSendsTheWebhookAfterTheResponse()
    {
        Http::fake([self::URL => Http::response(['result' => 'synced'], 200)]);
        config()->set('ecu.asset_change_webhook', [
            'url' => self::URL,
            'key' => '',
            'secret' => '',
            'timeout' => 5,
        ]);
        $asset = Asset::factory()->laptopMbp()->create();
        Http::fake([self::URL => Http::response(['result' => 'synced'], 200)]);

        $this->actingAsForApi(User::factory()->editAssets()->create())
            ->patchJson(route('api.assets.update', $asset), ['notes' => 'deferred over http'])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['event'] === 'updated'
            && $request['asset_id'] === $asset->id
            && in_array('notes', $request['changed'], true));
    }
}
