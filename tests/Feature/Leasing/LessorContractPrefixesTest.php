<?php

namespace Tests\Feature\Leasing;

use App\Models\Asset;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Leasing\LessorGuard;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A lessor's contract prefixes, declared on its Supplier record, decide which
 * lessor owns a lease contract. A prefix two lessors both match must never
 * pick one, because every email to a lessor names lease facts.
 */
class LessorContractPrefixesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Migrated databases carry the seeded lessors; start from none.
        DB::table('suppliers')->update(['contract_prefixes' => null]);
        $this->guard()->forgetPrefixes();
    }

    private function guard(): LessorGuard
    {
        return app(LessorGuard::class);
    }

    private function lessor(string $name, ?string $prefixes): Supplier
    {
        return Supplier::factory()->create(['name' => $name, 'contract_prefixes' => $prefixes]);
    }

    public function test_the_seed_does_nothing_when_the_lessors_do_not_exist(): void
    {
        DB::table('suppliers')->delete();
        $migration = require database_path('migrations/2026_10_04_130000_add_contract_prefixes_to_suppliers.php');

        $migration->seed();

        $this->assertSame(0, DB::table('suppliers')->count());
    }

    public function test_the_seed_stamps_existing_lessors_and_keeps_an_edited_value(): void
    {
        DB::table('suppliers')->delete();
        DB::table('suppliers')->insert([
            ['name' => 'CSI Leasing', 'contract_prefixes' => null],
            ['name' => 'CCA Financial', 'contract_prefixes' => 'EDITED-'],
        ]);
        $migration = require database_path('migrations/2026_10_04_130000_add_contract_prefixes_to_suppliers.php');

        $migration->seed();

        $this->assertSame('301452-', DB::table('suppliers')->where('name', 'CSI Leasing')->value('contract_prefixes'));
        $this->assertSame('EDITED-', DB::table('suppliers')->where('name', 'CCA Financial')->value('contract_prefixes'));
    }

    public function test_the_prefix_list_is_trimmed_upper_cased_and_longest_first(): void
    {
        $lessor = $this->lessor('Lessor A', ' qq- , QQ-77,, qq- ');

        $this->assertSame(['QQ-77', 'QQ-'], $lessor->contractPrefixList());
    }

    public function test_the_longest_matching_prefix_decides_the_lessor(): void
    {
        $a = $this->lessor('Lessor A', 'QQ-1');
        $b = $this->lessor('Lessor B', 'QQ-2,RR');

        $this->assertTrue($this->guard()->declaredLessorFor('qq-1-041426')->is($a));
        $this->assertTrue($this->guard()->lessorForContract('QQ-2-001')->is($b));
        $this->assertTrue($this->guard()->lessorForContract('RR77')->is($b));
        $this->assertTrue($this->guard()->isLeaseContract('QQ-1-9'));
        $this->assertFalse($this->guard()->isLeaseContract('QQ-3-9'));
        $this->assertSame(['QQ-1', 'QQ-2'], array_column(array_slice($this->guard()->declaredPrefixes(), 0, 2), 'prefix'));
    }

    public function test_a_declared_prefix_wins_over_the_assets_on_the_contract(): void
    {
        $declared = $this->lessor('Lessor A', 'QQ-');
        $other = $this->lessor('Lessor B', null);
        Asset::factory()->create(['lease_contract_id' => 'QQ-001', 'lessor_id' => $other->id]);

        $this->assertTrue($this->guard()->lessorForContract('QQ-002')->is($declared));
    }

    public function test_with_no_declared_prefix_the_assets_decide_as_before(): void
    {
        $lessor = $this->lessor('Lessor A', 'QQ-');
        $byAssets = $this->lessor('Lessor B', null);
        Asset::factory()->create(['lease_contract_id' => '700100-001', 'lessor_id' => $byAssets->id]);

        $this->assertTrue($this->guard()->lessorForContract('700100-009')->is($byAssets));
        $this->assertNull($this->guard()->declaredLessorFor('700100-009'));
        $this->assertNull($this->guard()->lessorForContract('999999-001'));
        $this->assertNotNull($lessor);
    }

    public function test_a_prefix_two_lessors_claim_resolves_to_no_lessor(): void
    {
        $a = $this->lessor('Lessor A', 'QQ-');
        $b = $this->lessor('Lessor B', 'RR-');
        // Bad data the save-time check would refuse, written past it.
        DB::table('suppliers')->where('id', $b->id)->update(['contract_prefixes' => 'QQ-1']);
        $this->guard()->forgetPrefixes();
        // The assets name one lessor; the clash must still not fall back to it.
        $asset = Asset::factory()->create(['lease_contract_id' => 'QQ-1-001', 'lessor_id' => $a->id]);

        $this->assertNull($this->guard()->lessorForContract('QQ-1-002'));
        $this->assertNull($this->guard()->declaredLessorFor('QQ-1-002'));
        $this->assertFalse($this->guard()->assetMatchesContract($asset));
        // A contract only one of them matches still resolves.
        $this->assertTrue($this->guard()->lessorForContract('QQ-2')->is($a));
    }

    public function test_an_asset_filed_under_a_lessor_other_than_the_declared_one_does_not_match(): void
    {
        $this->lessor('Lessor A', 'QQ-');
        $other = $this->lessor('Lessor B', null);
        $asset = Asset::factory()->create(['lease_contract_id' => 'QQ-001', 'lessor_id' => $other->id]);

        $this->assertFalse($this->guard()->assetMatchesContract($asset));
    }

    public function test_the_api_writes_and_returns_contract_prefixes(): void
    {
        $lessor = $this->lessor('Lessor A', null);
        $admin = User::factory()->superuser()->create();

        $this->actingAsForApi($admin)
            ->patchJson(route('api.suppliers.update', $lessor), ['contract_prefixes' => 'QQ-,ZZ'])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertSame('QQ-,ZZ', $lessor->fresh()->contract_prefixes);
        $this->actingAsForApi($admin)
            ->getJson(route('api.suppliers.show', $lessor))
            ->assertJson(['contract_prefixes' => 'QQ-,ZZ']);
    }

    public function test_the_api_refuses_a_prefix_another_lessor_claims(): void
    {
        $this->lessor('Lessor A', 'QQ-,ZZ');
        $b = $this->lessor('Lessor B', null);
        $admin = User::factory()->superuser()->create();

        foreach (['zz', 'QQ-1', 'Q', 'RR,QQ-'] as $clash) {
            $this->actingAsForApi($admin)
                ->patchJson(route('api.suppliers.update', $b), ['contract_prefixes' => $clash])
                ->assertOk()
                ->assertJson(['status' => 'error'])
                ->assertJsonStructure(['messages' => ['contract_prefixes']]);
        }

        $this->actingAsForApi($admin)
            ->postJson(route('api.suppliers.store'), ['name' => 'Lessor C', 'contract_prefixes' => 'QQ-'])
            ->assertOk()
            ->assertJson(['status' => 'error']);

        $this->assertNull($b->fresh()->contract_prefixes);
        $this->assertFalse(Supplier::where('name', 'Lessor C')->exists());
    }

    public function test_a_lessor_may_resave_its_own_prefixes(): void
    {
        $a = $this->lessor('Lessor A', 'QQ-');

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->patchJson(route('api.suppliers.update', $a), ['contract_prefixes' => 'QQ-,QQ-77'])
            ->assertOk()
            ->assertJson(['status' => 'success']);
    }

    public function test_the_web_form_saves_and_refuses_prefixes(): void
    {
        $this->lessor('Lessor A', 'QQ-');
        $b = $this->lessor('Lessor B', null);
        $admin = User::factory()->superuser()->create();

        $this->actingAs($admin)
            ->put(route('suppliers.update', $b), ['name' => 'Lessor B', 'contract_prefixes' => 'QQ-9'])
            ->assertSessionHasErrors('contract_prefixes');
        $this->assertNull($b->fresh()->contract_prefixes);

        $this->actingAs($admin)
            ->put(route('suppliers.update', $b), ['name' => 'Lessor B', 'contract_prefixes' => 'RR-'])
            ->assertSessionHasNoErrors();
        $this->assertSame('RR-', $b->fresh()->contract_prefixes);
    }

    public function test_the_lease_report_classifies_a_contract_by_its_declared_lessor(): void
    {
        $this->lessor('Lessor A', 'QQ-');
        $asset = Asset::factory()->create(['status_id' => Statuslabel::factory()->rtd()->create()->id]);
        Asset::query()->whereKey($asset->id)->update(['lease_contract_id' => 'QQ-003']);
        $unclaimed = Asset::factory()->create(['status_id' => Statuslabel::factory()->rtd()->create()->id]);
        Asset::query()->whereKey($unclaimed->id)->update(['lease_contract_id' => 'NOPE-004']);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.leases-operational', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee('QQ-003')
            ->assertSee('Lessor A')
            ->assertDontSee('NOPE-004');
    }
}
