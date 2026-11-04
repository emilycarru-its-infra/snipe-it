<?php

namespace Tests\Feature\UserAgreements\Api;

use App\Models\CheckoutAcceptance;
use App\Models\User;
use App\Models\UserAgreement;
use Tests\TestCase;

class CancelUserAgreementTest extends TestCase
{
    private function signedUpgrade(): UserAgreement
    {
        $acceptance = CheckoutAcceptance::factory()->accepted()->create();
        return UserAgreement::create([
            'agreement_type' => 'upgrade', 'lifecycle_stage' => 'agreement_signed',
            'user_id' => $acceptance->assigned_to_id, 'asset_id' => $acceptance->checkoutable_id,
            'checkout_acceptance_id' => $acceptance->id, 'signed_at' => $acceptance->accepted_at,
            'signed_pdf_path' => 'original-signed.pdf', 'top_up_amount' => 25,
        ]);
    }

    public function test_cancellation_is_audited_and_preserves_signed_evidence(): void
    {
        $agreement = $this->signedUpgrade();
        $signedAt = $agreement->signed_at->toIso8601String();
        $admin = User::factory()->superuser()->create();
        $this->actingAsForApi($admin)->postJson(route('api.user-agreements.cancel', $agreement), [
            'cancellation_reason' => 'Incorrect charge for a covered configuration.',
        ])->assertOk()->assertJsonPath('payload.lifecycle_stage', 'cancelled')
            ->assertJsonPath('payload.cancelled_by_id', $admin->id);
        $agreement->refresh();
        $this->assertNotNull($agreement->cancelled_at);
        $this->assertSame('Incorrect charge for a covered configuration.', $agreement->cancellation_reason);
        $this->assertSame($signedAt, $agreement->signed_at->toIso8601String());
        $this->assertSame('original-signed.pdf', $agreement->signed_pdf_path);
        $this->assertEquals(25, $agreement->top_up_amount);
        $this->postJson(route('api.user-agreements.cancel', $agreement), ['cancellation_reason' => 'Retry'])
            ->assertOk()->assertJsonPath('payload.cancellation_reason', 'Incorrect charge for a covered configuration.');
        $this->actingAs($admin, 'web')->get(route('user-agreements.show', $agreement))
            ->assertOk()->assertSee('Cancelled')->assertSee('Incorrect charge for a covered configuration.');
    }

    public function test_cancellation_requires_edit_permission_and_a_reason(): void
    {
        $agreement = $this->signedUpgrade();
        $this->actingAsForApi(User::factory()->create())->postJson(route('api.user-agreements.cancel', $agreement), [
            'cancellation_reason' => 'Incorrect charge.',
        ])->assertForbidden();
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.user-agreements.cancel', $agreement))->assertUnprocessable();
        $this->assertSame('agreement_signed', $agreement->fresh()->lifecycle_stage);
    }
}
