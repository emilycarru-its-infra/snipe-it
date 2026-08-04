<?php

namespace Tests\Feature\Orders\Api;

use App\Models\LeaseSchedule;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Support\BuildsExhibitWorkbooks;
use Tests\TestCase;

/**
 * The agent-session route into lease document intake: POST the file, read
 * the parsed preview back, POST again with commit=1 to write it.
 */
class LeaseDocumentsApiTest extends TestCase
{
    use BuildsExhibitWorkbooks;

    private function workbookUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'exhibit').'.xlsx';
        $this->writeExhibitWorkbook($path);

        return new UploadedFile($path, 'Exhibit A - Draft.xlsx', null, null, true);
    }

    public function test_parse_only_returns_the_extracted_fields()
    {
        $this->actingAsForApi(User::factory()->superuser()->create());

        $response = $this->postJson(route('api.lease-documents.store'), [
            'document' => $this->workbookUpload(),
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.committed', false)
            ->assertJsonPath('payload.parsed.schedule_ref', '900123-003')
            ->assertJsonPath('payload.parsed.totals.total_cost', 32311.8);

        $this->assertDatabaseMissing('lease_schedules', ['schedule_ref' => '900123-003']);
    }

    public function test_commit_writes_through_the_intake_service()
    {
        $this->actingAsForApi(User::factory()->superuser()->create());

        $response = $this->postJson(route('api.lease-documents.store'), [
            'document' => $this->workbookUpload(),
            'commit' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('payload.committed', true)
            ->assertJsonPath('payload.action', 'reconciled')
            ->assertJsonPath('payload.schedule.schedule_ref', '900123-003');

        $schedule = LeaseSchedule::where('schedule_ref', '900123-003')->first();
        $this->assertNotNull($schedule);
        $this->assertEquals(32311.8, (float) $schedule->expected_acquisition_cost);
        $this->assertSame(2, $schedule->expected_asset_count);
    }

    public function test_overrides_replace_parsed_fields()
    {
        $this->actingAsForApi(User::factory()->superuser()->create());

        $response = $this->postJson(route('api.lease-documents.store'), [
            'document' => $this->workbookUpload(),
            'schedule_ref' => '900123-004',
            'commit' => 1,
        ]);

        $response->assertOk()->assertJsonPath('payload.schedule.schedule_ref', '900123-004');
    }

    public function test_an_unrecognized_document_is_rejected()
    {
        $this->actingAsForApi(User::factory()->superuser()->create());

        $response = $this->postJson(route('api.lease-documents.store'), [
            'document' => UploadedFile::fake()->create('memo.pdf', 4, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
    }

    public function test_a_user_without_order_rights_is_denied()
    {
        $this->actingAsForApi(User::factory()->create());

        $this->postJson(route('api.lease-documents.store'), [
            'document' => $this->workbookUpload(),
        ])->assertForbidden();
    }
}
