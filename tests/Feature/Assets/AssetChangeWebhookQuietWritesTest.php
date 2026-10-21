<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\Location;
use App\Models\User;
use App\Services\AssetChangeNotifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Asset writes that skip model events still announce themselves.
 *
 * Audits, query-builder cascades and quiet saves never reach AssetObserver,
 * so without these announcements the listener only sees them at its next
 * reconciliation pass.
 */
class AssetChangeWebhookQuietWritesTest extends TestCase
{
    private const URL = 'https://automations.example.test/api/snipe-asset-changed';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        config()->set('ecu.asset_change_webhook', [
            'url' => self::URL,
            'key' => 'host-key',
            'secret' => 'shared-secret',
            'timeout' => 5,
        ]);
        Http::fake([self::URL => Http::response(['result' => 'synced'], 200)]);
    }

    private function forgetRequestsSoFar(): void
    {
        Http::fake([self::URL => Http::response(['result' => 'synced'], 200)]);
    }

    public function test_an_audit_announces_the_asset()
    {
        $asset = Asset::factory()->create();
        $this->forgetRequestsSoFar();

        $this->actingAsForApi(User::factory()->auditAssets()->create())
            ->postJson(route('api.asset.audit', $asset), ['note' => 'seen'])
            ->assertStatusMessageIs('success');

        Http::assertSent(fn ($request) => $request['event'] === 'audited'
            && $request['asset_id'] === $asset->id
            && $request->hasHeader('X-Asset-Change-Secret', 'shared-secret'));
    }

    public function test_moving_a_user_announces_the_assets_checked_out_to_them()
    {
        $user = User::factory()->create(['location_id' => Location::factory()->create()->id]);
        $assets = Asset::factory()->count(2)->assignedToUser($user)->create(['location_id' => $user->location_id]);
        $newLocation = Location::factory()->create();
        $this->forgetRequestsSoFar();

        $this->actingAsForApi(User::factory()->editUsers()->create())
            ->patchJson(route('api.users.update', $user), ['location_id' => $newLocation->id])
            ->assertStatusMessageIs('success');

        $this->assertEquals($newLocation->id, $assets[0]->fresh()->location_id);
        Http::assertSent(fn ($request) => $request['reason'] === 'user location'
            && $request['asset_count'] === 2
            && collect($request['asset_ids'])->sort()->values()->all() === $assets->pluck('id')->sort()->values()->all()
            && $request['changed'] === ['location_id']);
    }

    public function test_a_cascade_that_changes_nothing_is_not_announced()
    {
        $user = User::factory()->create(['location_id' => Location::factory()->create()->id]);
        Asset::factory()->assignedToUser($user)->create(['location_id' => $user->location_id]);
        $this->forgetRequestsSoFar();

        $this->actingAsForApi(User::factory()->editUsers()->create())
            ->patchJson(route('api.users.update', $user), ['location_id' => $user->location_id])
            ->assertStatusMessageIs('success');

        Http::assertNotSent(fn ($request) => ($request['reason'] ?? null) === 'user location');
    }

    public function test_ids_changed_by_treats_null_as_a_value()
    {
        $location = Location::factory()->create();
        $empty = Asset::factory()->create(['location_id' => null]);
        $there = Asset::factory()->create(['location_id' => $location->id]);
        $query = Asset::whereIn('id', [$empty->id, $there->id]);

        $this->assertEquals([$empty->id], AssetChangeNotifier::idsChangedBy($query, ['location_id' => $location->id]));
        $this->assertEquals([$there->id], AssetChangeNotifier::idsChangedBy($query, ['location_id' => null]));
    }

    public function test_the_location_sync_command_announces_its_moves_once()
    {
        $home = Location::factory()->create();
        $moved = Asset::factory()->create(['rtd_location_id' => $home->id, 'location_id' => null]);
        Asset::factory()->create(['rtd_location_id' => $home->id, 'location_id' => $home->id]);
        $this->forgetRequestsSoFar();

        $this->artisan('snipeit:sync-asset-locations')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['reason'] === 'location sync'
            && in_array($moved->id, $request['asset_ids'], true));
    }

    public function test_an_empty_set_sends_nothing()
    {
        AssetChangeNotifier::announceMany([], 'nothing');

        $this->assertFalse(app(AssetChangeNotifier::class)->notifyMany([], 'nothing'));
        Http::assertNothingSent();
    }

    public function test_a_long_list_is_capped_but_counted()
    {
        app(AssetChangeNotifier::class)->notifyMany(range(1, 600), 'large cascade', ['location_id']);

        Http::assertSent(fn ($request) => count($request['asset_ids']) === 500
            && $request['asset_count'] === 600
            && $request['asset_id'] === null);
    }
}
