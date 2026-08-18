<?php

namespace Tests\Feature\UserAgreements;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Statuslabel;
use App\Models\User;
use App\Models\UserAgreement;
use Tests\TestCase;

/**
 * Reaching the paperwork from the ledger.
 *
 * Finance reconciles against this table and the PDFs are the evidence
 * behind each amount. Before this they were only on a member's own page,
 * one member at a time, which is a lot of navigation to check a column.
 */
class LedgerPdfAccessTest extends TestCase
{
    private function agreement(string $type, array $overrides = []): UserAgreement
    {
        $model = AssetModel::factory()->create(['name' => 'MacBook Pro (14-inch, M5)']);
        $status = Statuslabel::factory()->rtd()->create();
        $asset = Asset::factory()->create(['model_id' => $model->id, 'status_id' => $status->id]);
        $user = User::factory()->create(['first_name' => 'Peter', 'last_name' => 'Bussigel']);

        return UserAgreement::create(array_merge([
            'user_id' => $user->id,
            'asset_id' => $asset->id,
            'agreement_type' => $type,
            'lifecycle_stage' => 'quoted',
            'base_program_price' => 2383.11,
            'device_cost' => 3457.14,
        ], $overrides));
    }

    public function test_the_ledger_links_a_generated_pdf_beside_its_amount()
    {
        $agreement = $this->agreement('pickup', ['pdf_path' => 'eula-pdfs/generated.pdf']);

        $this->actingAs(User::factory()->superuser()->create())
            // All-years: the ledger opens on the current program cycle and
            // this fixture belongs to no wave.
            ->get(route('reports.procurement.user-agreement-ledger', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee(route('user-agreements.pdf', $agreement->id), false)
            // The link opens in the record lightbox rather than a new tab.
            ->assertSee('rpt-doc js-lightbox', false)
            // …and the row can be picked for the bulk download.
            ->assertSee('data-rpt-row', false);
    }

    public function test_the_embedded_ledger_carries_the_same_selection()
    {
        // The dashboard lazy-loads this table as a bare embed; the toolbar
        // has to come with it or the checkboxes do nothing there.
        $this->agreement('pickup', ['pdf_path' => 'eula-pdfs/generated.pdf']);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.user-agreement-ledger', ['fiscal_year' => 'all', 'embed' => 1]))
            ->assertOk()
            ->assertSee('data-rpt-select-bar', false)
            ->assertSee('data-rpt-row', false);
    }

    public function test_an_agreement_with_no_pdf_on_file_offers_no_link()
    {
        // An icon promising a render that might fail is worse than no icon.
        $agreement = $this->agreement('pickup');

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.user-agreement-ledger', ['fiscal_year' => 'all']))
            ->assertOk()
            // The member is on the ledger; only the PDF link is withheld.
            ->assertSee('Peter Bussigel')
            ->assertDontSee(route('user-agreements.pdf', $agreement->id), false);
    }

    public function test_the_pdf_renders_inline_so_the_lightbox_can_show_it()
    {
        // An attachment disposition inside an iframe downloads the file and
        // leaves the frame blank, which is what the lightbox would get.
        $agreement = $this->agreement('pickup');

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('user-agreements.pdf', $agreement->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="user-agreement-'.$agreement->id.'-preview.pdf"');
    }

    public function test_the_download_flag_switches_it_back_to_a_save()
    {
        $agreement = $this->agreement('pickup');

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('user-agreements.pdf', ['userAgreement' => $agreement->id, 'download' => 1]))
            ->assertOk();

        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }

    public function test_selected_agreements_come_back_as_one_zip()
    {
        $first = $this->agreement('pickup');
        $second = $this->agreement('purchase');

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->post(route('user-agreements.bulk-pdf'), ['ids' => $first->id.','.$second->id])
            ->assertOk();

        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));

        // Streamed to disk, so read it back the way a browser would.
        $path = tempnam(sys_get_temp_dir(), 'ziptest');
        file_put_contents($path, $response->streamedContent());

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertSame(2, $zip->numFiles);
        $this->assertStringEndsWith('.pdf', $zip->getNameIndex(0));
        $this->assertStringStartsWith('%PDF-', $zip->getFromIndex(0));
        $zip->close();
        @unlink($path);
    }

    public function test_an_empty_selection_is_refused_rather_than_shipping_an_empty_zip()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->from(route('reports.procurement.user-agreement-ledger'))
            ->post(route('user-agreements.bulk-pdf'), ['ids' => ''])
            ->assertRedirect(route('reports.procurement.user-agreement-ledger'))
            ->assertSessionHas('error');
    }
}
