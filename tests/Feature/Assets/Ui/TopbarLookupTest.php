<?php

namespace Tests\Feature\Assets\Ui;

use App\Models\Asset;
use App\Models\StatusLabel;
use App\Models\User;
use Tests\TestCase;

class TopbarLookupTest extends TestCase
{
    public function test_redirects_to_asset_view_on_unique_tag_match(): void
    {
        $asset = Asset::factory()->create(['asset_tag' => 'ECU-1001']);

        $this->actingAs(User::factory()->viewAssets()->create())
            ->get(route('findbytag/hardware', ['assetTag' => 'ECU-1001']))
            ->assertRedirect(route('hardware.show', $asset->id));
    }

    public function test_redirects_to_asset_view_on_unique_serial_match(): void
    {
        $asset = Asset::factory()->create(['serial' => 'C02XK1ABJG5J']);

        $this->actingAs(User::factory()->viewAssets()->create())
            ->get(route('findbytag/hardware', ['assetTag' => 'C02XK1ABJG5J']))
            ->assertRedirect(route('hardware.show', $asset->id));
    }

    public function test_redirects_to_asset_view_on_unique_name_match(): void
    {
        $asset = Asset::factory()->create(['name' => 'ECUMAC-12345']);

        $this->actingAs(User::factory()->viewAssets()->create())
            ->get(route('findbytag/hardware', ['assetTag' => 'ECUMAC-12345']))
            ->assertRedirect(route('hardware.show', $asset->id));
    }

    public function test_redirects_to_hardware_index_when_no_match(): void
    {
        $this->actingAs(User::factory()->viewAssets()->create())
            ->get(route('findbytag/hardware', ['assetTag' => 'nope-nothing-here']))
            ->assertRedirect(route('hardware.index'))
            ->assertSessionHas('search', 'nope-nothing-here');
    }

    public function test_redirects_to_hardware_index_when_multiple_matches(): void
    {
        Asset::factory()->create(['asset_tag' => 'SHARED-VALUE']);
        Asset::factory()->create(['name' => 'SHARED-VALUE']);

        $this->actingAs(User::factory()->viewAssets()->create())
            ->get(route('findbytag/hardware', ['assetTag' => 'SHARED-VALUE']))
            ->assertRedirect(route('hardware.index'))
            ->assertSessionHas('search', 'SHARED-VALUE');
    }

    public function test_archived_assets_are_excluded_from_lookup(): void
    {
        $archived = StatusLabel::factory()->archived()->create();
        Asset::factory()->create(['serial' => 'OLD-SERIAL-1', 'status_id' => $archived->id]);

        $this->actingAs(User::factory()->viewAssets()->create())
            ->get(route('findbytag/hardware', ['assetTag' => 'OLD-SERIAL-1']))
            ->assertRedirect(route('hardware.index'))
            ->assertSessionHas('search', 'OLD-SERIAL-1');
    }

    public function test_empty_query_redirects_to_hardware_index(): void
    {
        $this->actingAs(User::factory()->viewAssets()->create())
            ->get(route('findbytag/hardware', ['assetTag' => '   ']))
            ->assertRedirect(route('hardware.index'));
    }
}
