<?php

namespace Tests\Feature\ProductIdentities;

use App\Models\License;
use App\Models\ProductIdentity;
use App\Services\ProductIdentity\ProductResolver;
use Tests\TestCase;

/**
 * The resolver is the join key: observed application name in, product and
 * licenses out. These pin the matching rules the catalogue relies on —
 * version independence, component rollup, platform scoping and precedence —
 * and the fiscal-year scoping of license links.
 */
class ProductResolverTest extends TestCase
{
    public function test_normalize_strips_versions_qualifiers_and_suffixes()
    {
        $this->assertSame('rhino', ProductResolver::normalize('Rhino 8'));
        $this->assertSame('autodesk maya', ProductResolver::normalize('Autodesk Maya 2025'));
        $this->assertSame('pro tools', ProductResolver::normalize('Pro Tools v2024.10.1'));
        $this->assertSame('blender', ProductResolver::normalize('blender.exe'));
        $this->assertSame('keyshot', ProductResolver::normalize('KeyShot 2024 (x64)'));
        $this->assertSame('cinema 4d', ProductResolver::normalize('Cinema 4D'));
        $this->assertSame('com.autodesk.maya', ProductResolver::normalize('com.autodesk.Maya'));
    }

    public function test_a_version_bump_resolves_to_the_same_product()
    {
        $rhino = ProductIdentity::factory()->withAliases([['any', 'exact', 'Rhino']])->create();
        $resolver = new ProductResolver;

        $this->assertTrue($rhino->is($resolver->resolve('Rhino 7')));
        $this->assertTrue($rhino->is($resolver->resolve('Rhino 8')));
        $this->assertNull($resolver->resolve('Rhinoceros Render'));
    }

    public function test_a_prefix_rolls_components_up_to_one_product()
    {
        $maya = ProductIdentity::factory()->withAliases([
            ['windows', 'prefix', 'Maya'],
            ['macos', 'prefix', 'com.autodesk.maya'],
        ])->create();
        $resolver = new ProductResolver;

        foreach (['Maya 2025', 'Maya Bifrost Extension', 'MayaUSD'] as $component) {
            $this->assertTrue($maya->is($resolver->resolve($component, 'Windows')), $component);
        }
        $this->assertTrue($maya->is($resolver->resolve('com.autodesk.Maya.2025', 'macOS')));
    }

    public function test_platform_aliases_only_match_their_platform()
    {
        ProductIdentity::factory()->withAliases([['macos', 'exact', 'com.mcneel.rhinoceros']])->create();
        $resolver = new ProductResolver;

        $this->assertNotNull($resolver->resolve('com.mcneel.rhinoceros', 'mac'));
        $this->assertNull($resolver->resolve('com.mcneel.rhinoceros', 'windows'));
        // With no platform reported, any alias may claim it.
        $this->assertNotNull($resolver->resolve('com.mcneel.rhinoceros'));
    }

    public function test_exact_beats_prefix_and_a_longer_prefix_beats_a_shorter_one()
    {
        $suite = ProductIdentity::factory()->withAliases([['any', 'prefix', 'Adobe']])->create();
        $photoshop = ProductIdentity::factory()->withAliases([['any', 'prefix', 'Adobe Photoshop']])->create();
        $bridge = ProductIdentity::factory()->withAliases([['any', 'exact', 'Adobe Bridge']])->create();
        $resolver = new ProductResolver;

        $this->assertTrue($photoshop->is($resolver->resolve('Adobe Photoshop 2025')));
        $this->assertTrue($bridge->is($resolver->resolve('Adobe Bridge 2025')));
        $this->assertTrue($suite->is($resolver->resolve('Adobe Illustrator')));
    }

    public function test_a_regex_alias_sees_the_raw_name()
    {
        $nuke = ProductIdentity::factory()->withAliases([['windows', 'regex', '^Nuke\d+\.\d+']])->create();
        $resolver = new ProductResolver;

        $this->assertTrue($nuke->is($resolver->resolve('Nuke15.1v3', 'windows')));
        $this->assertNull($resolver->resolve('NukeX', 'windows'));
    }

    public function test_license_links_are_scoped_by_fiscal_year_with_every_year_links_always_included()
    {
        $product = ProductIdentity::factory()->create();
        $always = License::factory()->create(['name' => 'Always']);
        $old = License::factory()->create(['name' => 'Old Contract']);
        $new = License::factory()->create(['name' => 'New Contract']);
        $product->licenses()->attach($always->id, ['fiscal_year' => null]);
        $product->licenses()->attach($old->id, ['fiscal_year' => 'FY2025-26']);
        $product->licenses()->attach($new->id, ['fiscal_year' => 'FY2026-27']);

        $this->assertSame(['Always', 'New Contract'], $product->licensesFor('FY26-27')->pluck('name')->all());
        $this->assertSame(['Always', 'Old Contract'], $product->licensesFor('FY2025-26')->pluck('name')->all());
        $this->assertCount(3, $product->licensesFor(null));
    }

    public function test_one_license_can_cover_several_products()
    {
        $bundle = License::factory()->create();
        $c4d = ProductIdentity::factory()->withAliases([['any', 'prefix', 'Cinema 4D']])->create();
        $redshift = ProductIdentity::factory()->withAliases([['any', 'prefix', 'Redshift']])->create();
        $bundle->productIdentities()->attach([$c4d->id, $redshift->id]);

        $report = (new ProductResolver)->partition([
            ['name' => 'Cinema 4D 2025', 'usage' => 10],
            ['name' => 'Redshift', 'usage' => 4],
        ]);

        $this->assertCount(2, $report['mapped']);
        foreach ($report['mapped'] as $product) {
            $this->assertSame([$bundle->id], array_column($product['licenses'], 'id'));
        }
    }

    public function test_partition_rolls_up_mapped_and_reports_unmapped_by_usage()
    {
        $maya = ProductIdentity::factory()->withAliases([['any', 'prefix', 'Maya']])->create(['name' => 'Autodesk Maya']);
        $maya->licenses()->attach(License::factory()->create()->id, ['fiscal_year' => 'FY2026-27']);
        ProductIdentity::factory()->withAliases([['any', 'exact', 'Harmony']])->create(['name' => 'Toon Boom Harmony']);

        $report = (new ProductResolver)->partition([
            ['name' => 'Maya 2025', 'platform' => 'windows', 'usage' => 30, 'devices' => 12],
            ['name' => 'Maya Bifrost Extension', 'platform' => 'windows', 'usage' => 5, 'devices' => 3],
            ['name' => 'Harmony 22', 'usage' => 8],
            ['name' => 'Marvelous Designer', 'usage' => 2],
            ['name' => 'Houdini FX', 'usage' => 40, 'devices' => 6],
            ['name' => 'Notepad++', 'usage' => 0.5],
        ], 'FY2026-27', 1);

        $this->assertSame('FY2026-27', $report['fiscal_year']);
        $this->assertSame(['Autodesk Maya', 'Toon Boom Harmony'], array_column($report['mapped'], 'name'));
        $this->assertEquals(35, $report['mapped'][0]['usage']);
        $this->assertCount(2, $report['mapped'][0]['applications']);
        $this->assertTrue($report['mapped'][0]['licensed']);
        $this->assertFalse($report['mapped'][1]['licensed']);
        $this->assertSame(['Houdini FX', 'Marvelous Designer'], array_column($report['unmapped'], 'name'));
        $this->assertSame(1, $report['unmapped_below_threshold']);
    }
}
