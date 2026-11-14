<?php

namespace Tests\Feature\Assets\Api;

use App\Models\Asset;
use App\Models\User;
use App\Models\UserAgreement;
use Tests\TestCase;

class AssetAgreementsTest extends TestCase
{
    public function test_lists_agreements_with_type_stage_and_signing_details()
    {
        $asset = Asset::factory()->create();
        $person = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Example']);

        $unsigned = new UserAgreement;
        $unsigned->forceFill([
            'agreement_type' => 'purchase',
            'lifecycle_stage' => 'quoted',
            'user_id' => $person->id,
            'asset_id' => $asset->id,
            'pdf_generated_at' => now(),
        ])->saveQuietly();

        $signed = new UserAgreement;
        $signed->forceFill([
            'agreement_type' => 'pickup',
            'lifecycle_stage' => 'agreement_signed',
            'user_id' => $person->id,
            'asset_id' => $asset->id,
            'signed_at' => now(),
            'signed_pdf_path' => 'signed-example.pdf',
        ])->saveQuietly();

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.assets.agreements', $asset))
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['key' => 'purchase', 'name' => 'Purchase'])
            ->assertJsonFragment(['key' => 'pickup', 'name' => 'New Laptop Pickup'])
            ->assertJsonFragment(['key' => 'agreement_signed', 'name' => 'Signed'])
            ->assertJsonFragment(['filename' => 'signed-example.pdf']);
    }

    public function test_requires_permission_to_view_the_asset()
    {
        $asset = Asset::factory()->create();

        $this->actingAsForApi(User::factory()->create())
            ->getJson(route('api.assets.agreements', $asset))
            ->assertForbidden();
    }

    public function test_requires_files_and_orders_permission_in_addition_to_assets_view()
    {
        $asset = Asset::factory()->create();

        $this->actingAsForApi(User::factory()->viewAssets()->create())
            ->getJson(route('api.assets.agreements', $asset))
            ->assertForbidden();
    }
}
