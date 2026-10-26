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
use App\Models\UserAgreement;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowObservationsTest extends TestCase
{
    public static function profiles(): array
    {
        return [['exhibit-projects', 'orders.view'], ['consumable-transactions', 'consumables.view'], ['user-agreements', 'orders.view']];
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

    public function test_agreement_observation_retains_stored_ledger_evidence_without_identity_or_document_disclosure(): void
    {
        Mail::fake();
        $person = User::factory()->create(['email' => 'excluded@example.test']);
        $asset = Asset::factory()->create();
        $agreement = UserAgreement::create([
            'agreement_type' => 'upgrade', 'lifecycle_stage' => 'agreement_signed',
            'user_id' => $person->id, 'asset_id' => $asset->id,
            'device_cost' => '987654321.1234', 'base_program_price' => null,
            'top_up_amount' => '123.4567', 'balance_paid' => '0.0000',
            'payment_method' => 'payroll_deduction', 'installment_count' => 12,
            'installment_amount' => '10.1234', 'signed_at' => '2026-10-12 13:14:15',
            'pdf_path' => 'private/excluded-unsigned.pdf', 'signed_pdf_path' => 'private/excluded-signed.pdf',
            'notes' => 'Excluded private ledger note',
        ]);
        $before = $agreement->fresh()->getRawOriginal();
        $reader = User::factory()->create(['permissions' => '{"orders.view":"1"}']);
        $response = $this->actingAsForApi($reader)->getJson('/api/v1/workflow-observations/user-agreements')->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $row = $response->json('rows.0');
        $this->assertEqualsCanonicalizing(['id', 'agreement_type', 'lifecycle_stage', 'user_id', 'asset_id',
            'base_program_price', 'device_cost', 'top_up_amount', 'buyout_cost', 'payment_method',
            'installment_count', 'installment_amount', 'balance_paid', 'balance_remaining',
            'terms_accepted_at', 'signed_at', 'sent_to_payroll_at', 'deployed_at', 'closed_at',
            'cancelled_at', 'created_at', 'updated_at', 'pdf_reference_present', 'signed_pdf_reference_present'], array_keys($row));
        $this->assertSame((string) $agreement->id, $row['id']);
        $this->assertSame((string) $person->id, $row['user_id']);
        $this->assertSame((string) $asset->id, $row['asset_id']);
        $this->assertSame('987654321.1234', $row['device_cost']);
        $this->assertSame('123.4567', $row['top_up_amount']);
        $this->assertSame('0.0000', $row['balance_paid']);
        $this->assertNull($row['base_program_price']);
        $this->assertSame(12, $row['installment_count']);
        $this->assertSame('agreement_signed', $row['lifecycle_stage']);
        $this->assertSame('2026-10-12 13:14:15', $row['signed_at']);
        $this->assertTrue($row['pdf_reference_present']);
        $this->assertTrue($row['signed_pdf_reference_present']);
        foreach (['excluded@example.test', 'private/excluded', 'Excluded private', $asset->serial] as $excluded) {
            $this->assertStringNotContainsString($excluded, $response->getContent());
        }
        $this->assertSame($before, $agreement->fresh()->getRawOriginal());
        Mail::assertNothingSent();
    }

    public function test_agreement_observation_keeps_unknowns_and_fixed_paging_without_deleted_rows(): void
    {
        $first = UserAgreement::create(['agreement_type' => 'pickup', 'lifecycle_stage' => 'eligible']);
        $second = UserAgreement::create(['agreement_type' => 'purchase', 'lifecycle_stage' => 'closed_buyout']);
        $deleted = UserAgreement::create(['agreement_type' => 'pickup', 'lifecycle_stage' => 'eligible']);
        $deleted->delete();
        $this->actingAsForApi(User::factory()->create(['permissions' => '{"orders.view":"1"}']));
        $this->getJson('/api/v1/workflow-observations/user-agreements?limit=1&offset=1&sort=agreement_type&order=desc')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.id', (string) $second->id)->assertJsonPath('rows.0.lifecycle_stage', 'closed_buyout');
        $row = $this->getJson('/api/v1/workflow-observations/user-agreements?limit=1')->assertOk()->json('rows.0');
        $this->assertSame((string) $first->id, $row['id']);
        foreach (['device_cost', 'base_program_price', 'signed_at', 'user_id', 'asset_id'] as $field) {
            $this->assertNull($row[$field]);
        }
        $this->assertFalse($row['pdf_reference_present']);
        $this->assertFalse($row['signed_pdf_reference_present']);
        $this->assertArrayNotHasKey('currency', $row);
        $this->assertArrayNotHasKey('fiscal_year', $row);
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
