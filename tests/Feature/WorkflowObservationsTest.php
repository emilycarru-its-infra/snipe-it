<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Consumable;
use App\Models\ConsumableTransaction;
use App\Models\Exhibit;
use App\Models\ExhibitProject;
use App\Models\ExhibitStatus;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowObservationsTest extends TestCase
{
    public static function profiles(): array
    {
        return [['exhibit-projects', 'orders.view'], ['consumable-transactions', 'consumables.view']];
    }

    private function project(array $fields = []): ExhibitProject
    {
        return ExhibitProject::create(array_merge([
            'exhibit_id' => Exhibit::firstOrCreate(['name' => 'Example exhibition'])->id,
            'status_id' => ExhibitStatus::firstOrCreate(['name' => 'Example queued'])->id,
            'year' => 2026,
            'student_name' => 'Example participant',
        ], $fields));
    }

    private function transaction(array $fields = []): ConsumableTransaction
    {
        return ConsumableTransaction::create(array_merge([
            'consumable_id' => Consumable::factory()->create()->id,
            'asset_id' => Asset::factory()->create()->id,
            'quantity' => 3,
            'gl_code' => 'EXAMPLE-GL',
            'unit_cost' => '123.4567',
            'total_cost' => '370.3701',
            'transaction_date' => '2026-05-01',
            'fiscal_year' => 'FY2026-27',
            'status' => 'transferred',
        ], $fields));
    }

    #[DataProvider('profiles')]
    public function test_each_profile_requires_existing_read_permission(string $profile, string $permission): void
    {
        $url = '/api/v1/workflow-observations/'.$profile;
        $unprivileged = User::factory()->create();
        $this->getJson($url)->assertUnauthorized();
        $this->actingAsForApi($unprivileged)->getJson($url)->assertForbidden();
        $otherPermission = $permission === 'orders.view' ? 'consumables.view' : 'orders.view';
        $this->actingAsForApi(User::factory()->create(['permissions' => json_encode([$otherPermission => '1'])]))
            ->getJson($url)->assertForbidden();
        $reader = User::factory()->create(['permissions' => json_encode([$permission => '1'])]);
        $this->actingAsForApi($reader)->getJson($url)->assertOk()->assertExactJson(['total' => 0, 'rows' => []]);
    }

    public function test_project_observation_retains_ids_without_linked_identity_details_or_writes(): void
    {
        $person = User::factory()->create(['email' => 'not-exported@example.test']);
        $project = $this->project(['user_id' => $person->id, 'project_details' => 'Example installation', 'approved' => true]);
        $before = $project->fresh()->getRawOriginal();
        $reader = User::factory()->create(['permissions' => '{"orders.view":"1"}']);
        $response = $this->actingAsForApi($reader)->getJson('/api/v1/workflow-observations/exhibit-projects')->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $row = $response->json('rows.0');
        $this->assertEqualsCanonicalizing(['id', 'exhibit_id', 'year', 'user_id', 'student_name', 'asset_id', 'status_id', 'project_type_id', 'project_details', 'requested_device', 'peripherals', 'submitted_file', 'approved', 'tdx_id', 'notes'], array_keys($row));
        $this->assertSame((string) $project->id, $row['id']);
        $this->assertSame((string) $person->id, $row['user_id']);
        $this->assertSame('Example installation', $row['project_details']);
        $this->assertTrue($row['approved']);
        $this->assertStringNotContainsString('not-exported@example.test', $response->getContent());
        $this->assertSame($before, $project->fresh()->getRawOriginal());
    }

    public function test_charge_observation_keeps_stored_decimal_strings_and_actual_state_without_currency_guess(): void
    {
        $txn = $this->transaction();
        $before = $txn->fresh()->getRawOriginal();
        $reader = User::factory()->viewConsumables()->create();
        $response = $this->actingAsForApi($reader)->getJson('/api/v1/workflow-observations/consumable-transactions')->assertOk();
        $row = $response->json('rows.0');
        $this->assertEqualsCanonicalizing(['id', 'consumable_id', 'asset_id', 'gl_code', 'quantity', 'unit_cost', 'total_cost', 'transaction_date', 'fiscal_year', 'status', 'notes'], array_keys($row));
        $this->assertSame((string) $txn->id, $row['id']);
        $this->assertSame('123.4567', $row['unit_cost']);
        $this->assertSame('370.3701', $row['total_cost']);
        $this->assertSame('transferred', $row['status']);
        $this->assertSame('2026-05-01', $row['transaction_date']);
        $this->assertSame($before, $txn->fresh()->getRawOriginal());
    }

    public function test_project_paging_is_fixed_to_id_and_excludes_deleted_records(): void
    {
        $first = $this->project(['student_name' => 'Z example']);
        $second = $this->project(['student_name' => 'A example']);
        $deleted = $this->project();
        $deleted->delete();
        $this->actingAsForApi(User::factory()->create(['permissions' => '{"orders.view":"1"}']))
            ->getJson('/api/v1/workflow-observations/exhibit-projects?limit=1&offset=1&sort=student_name&order=desc')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.id', (string) $second->id);
        $this->assertLessThan($second->id, $first->id);
    }

    public function test_unknown_cost_is_not_zero_and_large_stored_decimals_are_not_floated(): void
    {
        $unknown = $this->transaction(['unit_cost' => null, 'total_cost' => null]);
        $exact = $this->transaction(['quantity' => 1, 'unit_cost' => '987654321.1234', 'total_cost' => '987654321.1234']);
        $rows = $this->actingAsForApi(User::factory()->viewConsumables()->create())
            ->getJson('/api/v1/workflow-observations/consumable-transactions')->assertOk()->json('rows');
        $this->assertSame((string) $unknown->id, $rows[0]['id']);
        $this->assertNull($rows[0]['unit_cost']);
        $this->assertNull($rows[0]['total_cost']);
        $this->assertSame((string) $exact->id, $rows[1]['id']);
        $this->assertSame('987654321.1234', $rows[1]['unit_cost']);
        $this->assertSame('987654321.1234', $rows[1]['total_cost']);
    }

    public function test_charge_scope_preserves_company_visibility_and_excludes_voids_and_deleted_parents(): void
    {
        $this->settings->enableMultipleFullCompanySupport();
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $visible = Consumable::factory()->create(['company_id' => $own->id]);
        $hidden = Consumable::factory()->create(['company_id' => $other->id]);
        $kept = $this->transaction(['consumable_id' => $visible->id]);
        $void = $this->transaction(['consumable_id' => $visible->id]);
        $void->delete();
        $this->transaction(['consumable_id' => $hidden->id]);
        $retired = Consumable::factory()->create(['company_id' => $own->id]);
        $this->transaction(['consumable_id' => $retired->id]);
        $retired->delete();
        $reader = User::factory()->viewConsumables()->create(['company_id' => $own->id]);
        $this->actingAsForApi($reader)->getJson('/api/v1/workflow-observations/consumable-transactions')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.id', (string) $kept->id);
    }

    #[DataProvider('profiles')]
    public function test_invalid_paging_is_refused_and_reads_cannot_be_posted(string $profile, string $permission): void
    {
        $this->actingAsForApi(User::factory()->create(['permissions' => json_encode([$permission => '1'])]));
        foreach (['limit=0', 'limit=101', 'offset=-1', 'offset=10001', 'limit=1.5'] as $query) {
            $this->getJson('/api/v1/workflow-observations/'.$profile.'?'.$query)->assertUnprocessable();
        }
        $this->postJson('/api/v1/workflow-observations/'.$profile, [])->assertMethodNotAllowed();
    }
}
