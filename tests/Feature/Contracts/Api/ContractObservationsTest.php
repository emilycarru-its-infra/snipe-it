<?php

namespace Tests\Feature\Contracts\Api;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\ContractAttribute;
use App\Models\ContractSerial;
use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\User;
use Tests\TestCase;

class ContractObservationsTest extends TestCase
{
    private const BASE = '/api/v1/workflow-observations/';

    private function reader(): User
    {
        return User::factory()->create(['permissions' => '{"contracts.view":"1"}']);
    }

    public function test_observations_require_contract_read_permission(): void
    {
        // An installed instance has a user; without one every request goes to setup.
        User::factory()->create();
        $families = ['contract-assets', 'contract-serials', 'contract-attributes'];
        foreach ($families as $family) {
            $this->getJson(self::BASE.$family)->assertUnauthorized();
        }
        foreach ($families as $family) {
            $this->actingAsForApi(User::factory()->create(['permissions' => '{"assets.view":"1"}']))
                ->getJson(self::BASE.$family)->assertForbidden();
            $this->actingAsForApi($this->reader())->getJson(self::BASE.$family)
                ->assertOk()->assertExactJson(['total' => 0, 'rows' => []]);
        }
    }

    public function test_asset_links_keep_their_dates_and_mark_a_deleted_asset(): void
    {
        $contract = Contract::factory()->create();
        $kept = Asset::factory()->create();
        $gone = Asset::factory()->create();
        $contract->assets()->attach($kept->id, ['valid_from' => '2026-07-01', 'valid_to' => '2027-06-30', 'notes' => 'Example coverage']);
        $contract->assets()->attach($gone->id);
        $gone->delete();
        $retired = Contract::factory()->create();
        $retired->assets()->attach($kept->id);
        $retired->delete();

        $rows = $this->actingAsForApi($this->reader())->getJson(self::BASE.'contract-assets')
            ->assertOk()->assertJsonPath('total', 2)->json('rows');

        $this->assertSame(['id', 'contract_id', 'asset_id', 'valid_from', 'valid_to', 'notes', 'asset_deleted'], array_keys($rows[0]));
        $this->assertSame((string) $contract->id, $rows[0]['contract_id']);
        $this->assertSame((string) $kept->id, $rows[0]['asset_id']);
        $this->assertSame('2026-07-01', $rows[0]['valid_from']);
        $this->assertSame('2027-06-30', $rows[0]['valid_to']);
        $this->assertSame('Example coverage', $rows[0]['notes']);
        $this->assertFalse($rows[0]['asset_deleted']);
        $this->assertSame((string) $gone->id, $rows[1]['asset_id']);
        $this->assertTrue($rows[1]['asset_deleted']);
        $this->assertNull($rows[1]['valid_from']);
    }

    public function test_serials_and_attributes_of_live_contracts_page_in_id_order(): void
    {
        $contract = Contract::factory()->create();
        $first = ContractSerial::create(['contract_id' => $contract->id, 'serial' => 'EXAMPLE-SN-1', 'source' => 'tdx-assoc']);
        ContractSerial::create(['contract_id' => $contract->id, 'serial' => 'EXAMPLE-SN-2', 'source' => 'exhibit-a-draft', 'notes' => 'Line 2']);
        ContractAttribute::create(['contract_id' => $contract->id, 'name' => 'lease_rate_factor', 'value' => '0.25471']);
        $retired = Contract::factory()->create();
        ContractSerial::create(['contract_id' => $retired->id, 'serial' => 'EXAMPLE-SN-3', 'source' => 'manual']);
        $retired->delete();

        $reader = $this->reader();
        $serials = $this->actingAsForApi($reader)->getJson(self::BASE.'contract-serials?limit=1')
            ->assertOk()->assertJsonPath('total', 2)->json('rows');
        $this->assertSame([['id' => (string) $first->id, 'contract_id' => (string) $contract->id,
            'serial' => 'EXAMPLE-SN-1', 'source' => 'tdx-assoc', 'notes' => null]], $serials);
        $second = $this->actingAsForApi($reader)->getJson(self::BASE.'contract-serials?limit=1&offset=1')->json('rows.0');
        $this->assertSame('EXAMPLE-SN-2', $second['serial']);
        $this->assertSame('Line 2', $second['notes']);

        $attributes = $this->actingAsForApi($reader)->getJson(self::BASE.'contract-attributes')
            ->assertOk()->json('rows');
        $this->assertSame([['id' => $attributes[0]['id'], 'contract_id' => (string) $contract->id,
            'name' => 'lease_rate_factor', 'value' => '0.25471']], $attributes);

        $this->actingAsForApi($reader)->getJson(self::BASE.'contract-serials?limit=101')->assertStatus(422);
    }

    public function test_license_seats_carry_their_holder_by_id_only(): void
    {
        $person = User::factory()->create(['first_name' => 'Excluded', 'email' => 'excluded@example.test']);
        $asset = Asset::factory()->create();
        $license = License::factory()->create(['seats' => 3]);
        $seats = LicenseSeat::where('license_id', $license->id)->orderBy('id')->get();
        $seats[0]->update(['assigned_to' => $person->id, 'notes' => 'Example seat note']);
        $seats[1]->update(['asset_id' => $asset->id]);
        $retired = License::factory()->create(['seats' => 1]);
        $retired->delete();

        $this->actingAsForApi(User::factory()->create(['permissions' => '{"contracts.view":"1"}']))
            ->getJson(self::BASE.'license-seats')->assertForbidden();
        $rows = $this->actingAsForApi(User::factory()->create(['permissions' => '{"licenses.view":"1"}']))
            ->getJson(self::BASE.'license-seats')->assertOk()->assertJsonPath('total', 3)->json('rows');

        $this->assertSame(['id', 'license_id', 'assigned_to', 'asset_id', 'unreassignable', 'notes', 'updated_at'], array_keys($rows[0]));
        $this->assertSame((string) $person->id, $rows[0]['assigned_to']);
        $this->assertNull($rows[0]['asset_id']);
        $this->assertSame('Example seat note', $rows[0]['notes']);
        $this->assertSame((string) $asset->id, $rows[1]['asset_id']);
        $this->assertNull($rows[2]['assigned_to']);
        $this->assertFalse($rows[2]['unreassignable']);
        $this->assertStringNotContainsString('excluded', strtolower(json_encode($rows)));
    }
}
