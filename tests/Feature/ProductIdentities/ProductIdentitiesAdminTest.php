<?php

namespace Tests\Feature\ProductIdentities;

use App\Models\License;
use App\Models\ProductIdentity;
use App\Models\User;
use Tests\TestCase;

/**
 * The catalogue has to be maintainable by an admin without a code change:
 * create a product with its aliases and license links on one form, edit it,
 * delete it, and see which licenses nothing links to yet.
 */
class ProductIdentitiesAdminTest extends TestCase
{
    private function admin(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_index_lists_products_and_licenses_with_no_product()
    {
        $product = ProductIdentity::factory()->withAliases([['any', 'prefix', 'Maya']])->create(['name' => 'Autodesk Maya']);
        $linked = License::factory()->create(['name' => 'Linked Licence']);
        $product->licenses()->attach($linked->id);
        License::factory()->create(['name' => 'Orphan Licence']);

        $this->actingAs($this->admin())
            ->get(route('product-identities.index'))
            ->assertOk()
            ->assertSee('Autodesk Maya')
            ->assertSee(trans('admin/productidentities/general.unlinked_title'))
            ->assertSee('Orphan Licence')
            ->assertDontSee('Linked Licence');
    }

    public function test_index_can_try_a_name_against_the_catalogue()
    {
        ProductIdentity::factory()->withAliases([['windows', 'prefix', 'Maya']])->create(['name' => 'Autodesk Maya']);

        $this->actingAs($this->admin())
            ->get(route('product-identities.index', ['probe' => 'Maya Bifrost Extension', 'platform' => 'windows']))
            ->assertOk()
            ->assertSee(trans('admin/productidentities/general.probe_resolves', ['name' => 'Maya Bifrost Extension']));

        $this->actingAs($this->admin())
            ->get(route('product-identities.index', ['probe' => 'Maya Bifrost Extension', 'platform' => 'macos']))
            ->assertOk()
            ->assertSee(trans('admin/productidentities/general.probe_unresolved', ['name' => 'Maya Bifrost Extension']));
    }

    public function test_create_saves_aliases_and_license_links_and_drops_blank_rows()
    {
        $lab = License::factory()->create();
        $home = License::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('product-identities.store'), [
                'name'             => 'SOLIDWORKS',
                'publisher'        => 'Dassault Systèmes',
                'procurement_name' => 'Solidworks Education',
                'aliases'          => [
                    ['platform' => 'windows', 'match_type' => 'prefix', 'pattern' => 'SOLIDWORKS'],
                    ['platform' => 'any', 'match_type' => 'prefix', 'pattern' => ''],
                ],
                'license_links'    => [
                    ['license_id' => $lab->id, 'fiscal_year' => ''],
                    ['license_id' => $home->id, 'fiscal_year' => 'FY26-27'],
                    ['license_id' => '', 'fiscal_year' => ''],
                ],
            ])
            ->assertRedirect();

        $product = ProductIdentity::where('name', 'SOLIDWORKS')->firstOrFail();
        $this->assertSame('Solidworks Education', $product->procurement_name);
        $this->assertSame(['SOLIDWORKS'], $product->aliases->pluck('pattern')->all());
        $this->assertDatabaseHas('license_product_identity', ['product_identity_id' => $product->id, 'license_id' => $lab->id, 'fiscal_year' => null]);
        $this->assertDatabaseHas('license_product_identity', ['product_identity_id' => $product->id, 'license_id' => $home->id, 'fiscal_year' => 'FY2026-27']);
    }

    public function test_update_replaces_the_aliases_and_links()
    {
        $old = License::factory()->create();
        $new = License::factory()->create();
        $product = ProductIdentity::factory()->withAliases([['any', 'exact', 'Old Name']])->create();
        $product->licenses()->attach($old->id);

        $this->actingAs($this->admin())
            ->put(route('product-identities.update', $product), [
                'name'          => $product->name,
                'aliases'       => [['platform' => 'macos', 'match_type' => 'exact', 'pattern' => 'com.example.new']],
                'license_links' => [['license_id' => $new->id, 'fiscal_year' => '']],
            ])
            ->assertRedirect(route('product-identities.show', $product));

        $product->refresh();
        $this->assertSame(['com.example.new'], $product->aliases->pluck('pattern')->all());
        $this->assertSame([$new->id], $product->licenses->pluck('id')->all());
    }

    public function test_a_broken_regex_or_fiscal_year_is_refused_without_saving()
    {
        $license = License::factory()->create();

        $this->actingAs($this->admin())
            ->from(route('product-identities.create'))
            ->post(route('product-identities.store'), [
                'name'          => 'Broken',
                'aliases'       => [['platform' => 'any', 'match_type' => 'regex', 'pattern' => '(unclosed']],
                'license_links' => [['license_id' => $license->id, 'fiscal_year' => 'next year']],
            ])
            ->assertRedirect(route('product-identities.create'))
            ->assertSessionHasErrors(['aliases.0.pattern', 'license_links.0.fiscal_year']);

        $this->assertDatabaseMissing('product_identities', ['name' => 'Broken']);
    }

    public function test_rejected_input_is_escaped_when_the_form_shows_it_back()
    {
        $license = License::factory()->create();

        $this->actingAs($this->admin())
            ->from(route('product-identities.create'))
            ->followingRedirects()
            ->post(route('product-identities.store'), [
                'name'          => 'Escaped',
                'aliases'       => [['platform' => 'any', 'match_type' => 'regex', 'pattern' => '<b id="pi-xss">(']],
                'license_links' => [['license_id' => $license->id, 'fiscal_year' => '<i id="pi-fy">']],
            ])
            ->assertOk()
            ->assertDontSee('<b id="pi-xss">', false)
            ->assertDontSee('<i id="pi-fy">', false)
            ->assertSee('&lt;b id=&quot;pi-xss&quot;&gt;(', false);
    }

    public function test_edit_and_show_render()
    {
        $product = ProductIdentity::factory()->withAliases([['windows', 'prefix', 'Houdini']])->create();
        $product->licenses()->attach(License::factory()->create(['name' => 'Houdini Pool'])->id, ['fiscal_year' => 'FY2026-27']);

        $this->actingAs($this->admin())->get(route('product-identities.show', $product))
            ->assertOk()->assertSee('Houdini')->assertSee('Houdini Pool')->assertSee('FY2026-27');
        $this->actingAs($this->admin())->get(route('product-identities.edit', $product))
            ->assertOk()->assertSee('value="Houdini"', false);
    }

    public function test_delete_removes_the_product_but_not_its_licenses()
    {
        $license = License::factory()->create();
        $product = ProductIdentity::factory()->withAliases([['any', 'exact', 'Gone']])->create();
        $product->licenses()->attach($license->id);

        $this->actingAs($this->admin())
            ->delete(route('product-identities.destroy', $product))
            ->assertRedirect(route('product-identities.index'));

        $this->assertSoftDeleted($product);
        $this->assertDatabaseMissing('product_identity_aliases', ['product_identity_id' => $product->id]);
        $this->assertDatabaseMissing('license_product_identity', ['product_identity_id' => $product->id]);
        $this->assertNotNull($license->fresh());
    }

    public function test_non_admins_cannot_reach_the_catalogue()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('product-identities.index'))
            ->assertForbidden();
    }
}
