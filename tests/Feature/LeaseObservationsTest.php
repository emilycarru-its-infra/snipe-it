<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LeaseObservationsTest extends TestCase
{
    private const URL = '/api/v1/workflow-observations/leases';

    public function test_lease_observations_require_the_existing_asset_read_permission(): void
    {
        $unprivileged = User::factory()->create();
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actingAsForApi($unprivileged)->getJson(self::URL)->assertForbidden();
        $this->actingAsForApi(User::factory()->create(['permissions' => '{"orders.view":"1"}']))->getJson(self::URL)->assertForbidden();
        $this->actingAsForApi(User::factory()->viewAssets()->create())->getJson(self::URL)->assertOk()
            ->assertExactJson(['total' => 0, 'rows' => []]);
    }

    public function test_stored_lease_facts_do_not_expand_contacts_or_assert_return_state(): void
    {
        Mail::fake();
        $person = User::factory()->create(['email' => 'excluded@example.test']);
        $lessor = Supplier::factory()->create(['name' => 'Excluded supplier label', 'email' => 'excluded-supplier@example.test']);
        $asset = Asset::factory()->create([
            'name' => 'Excluded asset display name', 'assigned_to' => $person->id, 'assigned_type' => User::class,
            'lessor_id' => $lessor->id, 'ownership_type' => 'Purchased',
            'lease_contract_id' => 'EXAMPLE-LEASE-01', 'lease_contract_name' => 'Example & retained term',
            'lease_end_date' => '2027-06-30', 'lease_rent' => '987654321.12',
            'buyout_cost' => null, 'decommission_date' => '2026-10-12',
        ]);
        $before = $asset->fresh()->getRawOriginal();
        $response = $this->actingAsForApi(User::factory()->viewAssets()->create())->getJson(self::URL)->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $row = $response->json('rows.0');
        $this->assertEqualsCanonicalizing(['id', 'asset_tag', 'serial', 'lessor_id', 'lease_contract_id',
            'lease_contract_name', 'ownership_type', 'lease_end_date', 'lease_rent', 'buyout_cost', 'decommission_date'], array_keys($row));
        $this->assertSame((string) $asset->id, $row['id']);
        $this->assertSame((string) $lessor->id, $row['lessor_id']);
        $this->assertSame((string) $asset->asset_tag, $row['asset_tag']);
        $this->assertSame('987654321.12', $row['lease_rent']);
        $this->assertNull($row['buyout_cost']);
        $this->assertSame('Purchased', $row['ownership_type']);
        $this->assertSame('Example & retained term', $row['lease_contract_name']);
        $this->assertSame('2027-06-30', $row['lease_end_date']);
        $this->assertSame('2026-10-12', $row['decommission_date']);
        foreach (['excluded@example.test', 'excluded-supplier@example.test', 'Excluded asset', 'Excluded supplier'] as $text) {
            $this->assertStringNotContainsString($text, $response->getContent());
        }
        $this->assertArrayNotHasKey('currency', $row);
        $this->assertArrayNotHasKey('returned_on', $row);
        $this->assertSame($before, $asset->fresh()->getRawOriginal());
        Mail::assertNothingSent();
    }

    public function test_company_scope_soft_deletion_and_unrelated_assets_are_retained(): void
    {
        $this->settings->enableMultipleFullCompanySupport();
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $kept = Asset::factory()->create(['company_id' => $own->id, 'ownership_type' => 'Lease', 'lease_rent' => '0.00']);
        Asset::factory()->create(['company_id' => $other->id, 'ownership_type' => 'Lease']);
        $deleted = Asset::factory()->create(['company_id' => $own->id, 'lease_contract_id' => 'EXAMPLE-DELETED']);
        $deleted->delete();
        Asset::factory()->create(['company_id' => $own->id, 'ownership_type' => 'Purchased', 'lessor_id' => null,
            'lease_contract_id' => null, 'lease_contract_name' => null, 'lease_end_date' => null, 'lease_rent' => null, 'buyout_cost' => null]);
        $this->actingAsForApi(User::factory()->viewAssets()->create(['company_id' => $own->id]))
            ->getJson(self::URL)->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.id', (string) $kept->id)->assertJsonPath('rows.0.lease_rent', '0.00');
    }

    public function test_fixed_id_paging_retains_unknown_prices_and_supplied_dates(): void
    {
        $first = Asset::factory()->create(['asset_tag' => 'EXAMPLE-Z', 'lease_contract_id' => 'EXAMPLE-01', 'lease_rent' => null]);
        $second = Asset::factory()->create(['asset_tag' => 'EXAMPLE-A', 'ownership_type' => 'Lease to Own']);
        $this->actingAsForApi(User::factory()->viewAssets()->create());
        $this->getJson(self::URL.'?limit=1&offset=1&sort=asset_tag&order=desc')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('rows.0.id', (string) $second->id);
        $this->getJson(self::URL.'?limit=1')->assertOk()->assertJsonPath('rows.0.id', (string) $first->id)
            ->assertJsonPath('rows.0.lease_rent', null);
    }

    public function test_paging_is_bounded_and_the_collection_cannot_be_posted(): void
    {
        $this->actingAsForApi(User::factory()->viewAssets()->create());
        foreach (['limit=0', 'limit=101', 'offset=-1', 'offset=20001', 'limit=1.5'] as $query) {
            $this->getJson(self::URL.'?'.$query)->assertUnprocessable();
        }
        $this->getJson(self::URL.'?offset=20000')->assertOk();
        $this->postJson(self::URL, [])->assertMethodNotAllowed();
    }
}
