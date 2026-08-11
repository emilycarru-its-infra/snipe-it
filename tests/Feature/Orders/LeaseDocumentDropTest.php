<?php

namespace Tests\Feature\Orders;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Support\BuildsExhibitWorkbooks;
use Tests\TestCase;

/**
 * The web drop zone: a dropped file is parsed into an editable preview, and
 * committing the preview writes through the same intake service as the API.
 */
class LeaseDocumentDropTest extends TestCase
{
    use BuildsExhibitWorkbooks;

    private function workbookUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'exhibit').'.xlsx';
        $this->writeExhibitWorkbook($path);

        return new UploadedFile($path, 'Exhibit A - Draft.xlsx', null, null, true);
    }

    public function test_parse_shows_an_editable_preview_without_saving()
    {
        $this->actingAs(User::factory()->superuser()->create());

        $response = $this->post(route('lease-documents.parse'), [
            'document' => $this->workbookUpload(),
        ]);

        $response->assertOk()
            ->assertViewIs('lease-intake.preview')
            ->assertSee('900123-003')
            ->assertSee('TESTSER001');

        $this->assertDatabaseMissing('lease_schedules', ['schedule_ref' => '900123-003']);
    }

    public function test_commit_writes_the_previewed_document()
    {
        $this->actingAs(User::factory()->superuser()->create());

        $preview = $this->post(route('lease-documents.parse'), [
            'document' => $this->workbookUpload(),
        ]);

        $response = $this->post(route('lease-documents.commit'), [
            'token' => $preview->viewData('token'),
            'original_name' => $preview->viewData('original_name'),
            'schedule_ref' => '900123-003',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('lease_schedules', ['schedule_ref' => '900123-003']);
    }

    public function test_the_hub_leases_tab_offers_the_drop_zone()
    {
        $this->actingAs(User::factory()->superuser()->create());

        $this->followingRedirects()->get(route('procurement.index'))
            ->assertOk()
            ->assertSee(route('lease-documents.parse'));
    }
}
