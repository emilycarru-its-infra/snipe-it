<?php

namespace Tests\Feature\ProductIdentities;

use App\Models\License;
use App\Models\ProductIdentity;
use App\Models\User;
use Tests\TestCase;

/**
 * The resolve endpoint is what a usage feed calls: a batch of observed
 * applications in, products, licenses and the unmapped remainder out.
 */
class ProductIdentitiesApiTest extends TestCase
{
    public function test_resolve_requires_permission()
    {
        $this->actingAsForApi(User::factory()->create())
            ->postJson(route('api.productidentities.resolve'), ['applications' => [['name' => 'Maya']]])
            ->assertForbidden();
    }

    public function test_resolve_returns_mapped_products_and_unmapped_applications()
    {
        $maya = ProductIdentity::factory()->withAliases([['any', 'prefix', 'Maya']])->create(['name' => 'Autodesk Maya']);
        $license = License::factory()->create();
        $maya->licenses()->attach($license->id, ['fiscal_year' => 'FY2026-27']);
        $user = User::factory()->create(['permissions' => json_encode(['licensemodels.view' => '1'])]);

        $this->actingAsForApi($user)
            ->postJson(route('api.productidentities.resolve'), [
                'fiscal_year'  => 'FY2026-27',
                'min_usage'    => 1,
                'applications' => [
                    ['name' => 'Maya 2025', 'platform' => 'windows', 'usage' => 12, 'devices' => 4],
                    ['name' => 'Houdini FX', 'platform' => 'windows', 'usage' => 20],
                    ['name' => 'Calculator', 'usage' => 0],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('mapped.0.name', 'Autodesk Maya')
            ->assertJsonPath('mapped.0.licenses.0.id', $license->id)
            ->assertJsonPath('mapped.0.licensed', true)
            ->assertJsonPath('unmapped.0.name', 'Houdini FX')
            ->assertJsonPath('unmapped_below_threshold', 1);
    }

    public function test_index_lists_the_catalogue()
    {
        ProductIdentity::factory()->withAliases([['macos', 'exact', 'com.mcneel.rhinoceros']])->create(['name' => 'Rhino']);

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.productidentities.index'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.aliases.0.pattern', 'com.mcneel.rhinoceros');
    }
}
