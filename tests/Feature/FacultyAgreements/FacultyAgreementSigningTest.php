<?php

namespace Tests\Feature\FacultyAgreements;

use App\Events\CheckoutAccepted;
use App\Listeners\UpdateFacultyAgreementOnAccept;
use App\Models\Asset;
use App\Models\CheckoutAcceptance;
use App\Models\FacultyAgreement;
use App\Models\Statuslabel;
use App\Models\User;
use Tests\TestCase;

class FacultyAgreementSigningTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function newAssignedAsset(): Asset
    {
        $status = Statuslabel::factory()->rtd()->create();

        return Asset::factory()->create(['status_id' => $status->id]);
    }

    public function test_send_for_signature_creates_acceptance_with_rendered_eula()
    {
        $faculty = User::factory()->create(['first_name' => 'Carlo', 'last_name' => 'Ghioni']);
        $asset = $this->newAssignedAsset();

        $agreement = FacultyAgreement::create([
            'agreement_type' => 'lease_end_purchase',
            'user_id' => $faculty->id,
            'asset_id' => $asset->id,
            'lifecycle_stage' => 'quoted',
            'buyout_cost' => 350,
        ]);

        $this->actingAs($this->superuser())
            ->post(route('faculty-agreements.send-for-signature', $agreement))
            ->assertRedirect(route('faculty-agreements.show', $agreement));

        $agreement->refresh();
        $this->assertNotNull($agreement->checkout_acceptance_id);
        $this->assertEquals('agreement_sent', $agreement->lifecycle_stage);

        $acceptance = CheckoutAcceptance::find($agreement->checkout_acceptance_id);
        $this->assertNotNull($acceptance);
        // EULA body picks up the faculty name and the buyout amount
        // from the merge variables.
        $this->assertStringContainsString('Carlo Ghioni', $acceptance->eula_text_override);
        $this->assertStringContainsString('$350.00', $acceptance->eula_text_override);
    }

    public function test_send_without_asset_or_user_returns_an_error_redirect()
    {
        $agreement = FacultyAgreement::create([
            'agreement_type' => 'pickup',
            'lifecycle_stage' => 'eligible',
        ]);

        $this->actingAs($this->superuser())
            ->post(route('faculty-agreements.send-for-signature', $agreement))
            ->assertRedirect(route('faculty-agreements.show', $agreement))
            ->assertSessionHas('error', trans('admin/faculty-agreements/message.missing_asset_or_user'));

        $this->assertNull($agreement->fresh()->checkout_acceptance_id);
    }

    public function test_stage_change_to_agreement_sent_auto_creates_acceptance()
    {
        $faculty = User::factory()->create();
        $asset = $this->newAssignedAsset();

        $agreement = FacultyAgreement::create([
            'agreement_type' => 'upgrade',
            'user_id' => $faculty->id,
            'asset_id' => $asset->id,
            'lifecycle_stage' => 'quoted',
            'base_program_price' => 2200,
            'device_cost' => 3400,
            'top_up_amount' => 1200,
        ]);

        $this->assertNull($agreement->checkout_acceptance_id);

        // Editing the stage directly (no button) should still kick off
        // acceptance creation via the saved() hook.
        $agreement->lifecycle_stage = 'agreement_sent';
        $agreement->save();

        $this->assertNotNull($agreement->fresh()->checkout_acceptance_id);
    }

    public function test_checkout_accepted_event_marks_agreement_signed()
    {
        $faculty = User::factory()->create();
        $asset = $this->newAssignedAsset();
        $agreement = FacultyAgreement::create([
            'agreement_type' => 'pickup',
            'user_id' => $faculty->id,
            'asset_id' => $asset->id,
            'lifecycle_stage' => 'agreement_sent',
        ]);

        // Booted hook above created the acceptance; pretend Snipe accepted it.
        $acceptance = CheckoutAcceptance::find($agreement->fresh()->checkout_acceptance_id);
        $this->assertNotNull($acceptance);
        $acceptance->accepted_at = now();
        $acceptance->stored_eula_file = 'accepted-test.pdf';
        $acceptance->save();

        (new UpdateFacultyAgreementOnAccept)->handle(new CheckoutAccepted($acceptance));

        $agreement->refresh();
        $this->assertEquals('agreement_signed', $agreement->lifecycle_stage);
        $this->assertNotNull($agreement->signed_at);
        $this->assertEquals('accepted-test.pdf', $agreement->signed_pdf_path);
    }

    public function test_unsigned_pdf_preview_returns_pdf_content_type()
    {
        $faculty = User::factory()->create();
        $asset = $this->newAssignedAsset();
        $agreement = FacultyAgreement::create([
            'agreement_type' => 'pickup',
            'user_id' => $faculty->id,
            'asset_id' => $asset->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $response = $this->actingAs($this->superuser())
            ->get(route('faculty-agreements.pdf', $agreement));

        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_show_view_renders_eula_preview()
    {
        $faculty = User::factory()->create(['first_name' => 'Daphne', 'last_name' => 'Plessner']);
        $asset = $this->newAssignedAsset();
        $agreement = FacultyAgreement::create([
            'agreement_type' => 'lease_end_purchase',
            'user_id' => $faculty->id,
            'asset_id' => $asset->id,
            'lifecycle_stage' => 'quoted',
            'buyout_cost' => 450,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('faculty-agreements.show', $agreement))
            ->assertOk()
            ->assertSee('Daphne Plessner')
            ->assertSee('$450.00');
    }
}
