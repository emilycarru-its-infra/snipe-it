<?php

namespace Tests\Feature\UserAgreements;

use App\Models\CheckoutAcceptance;
use App\Models\User;
use App\Models\UserAgreement;
use App\Notifications\AcceptanceItemAcceptedToUserNotification;
use App\Services\UserAgreements\PickupInstructionsSender;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PickupInstructionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['forms.pickup_auto_create.booking_url' => 'https://example.org/pickup']);
        Notification::fake();
    }

    private function pickup(bool $accepted = true): UserAgreement
    {
        $factory = CheckoutAcceptance::factory();
        $acceptance = ($accepted ? $factory->accepted() : $factory->pending())->create();
        return UserAgreement::create([
            'agreement_type' => 'pickup',
            'lifecycle_stage' => $accepted ? 'agreement_signed' : 'agreement_sent',
            'signed_at' => $acceptance->accepted_at,
            'signed_pdf_path' => $accepted ? 'original-signed.pdf' : null,
            'user_id' => $acceptance->assigned_to_id,
            'asset_id' => $acceptance->checkoutable_id,
            'checkout_acceptance_id' => $acceptance->id,
        ]);
    }

    public function test_completed_pickup_can_receive_its_configured_instructions_once(): void
    {
        $agreement = $this->pickup();
        $signedAt = $agreement->signed_at->toIso8601String();
        $admin = User::factory()->superuser()->create();
        $this->actingAsForApi($admin)
            ->postJson(route('api.user-agreements.send-pickup-instructions', $agreement))
            ->assertOk()->assertJsonPath('status', 'success');
        $this->assertNotNull($agreement->fresh()->pickup_instructions_sent_at);
        $this->assertNull($agreement->fresh()->pickup_instructions_sending_at);
        Notification::assertSentTo($agreement->user, AcceptanceItemAcceptedToUserNotification::class,
            fn ($notification) => $notification->pickup_agreement
                && $notification->item_tag === $agreement->asset->asset_tag
                && str_contains((string) $notification->toMail()->render(), 'https://example.org/pickup'));
        $this->postJson(route('api.user-agreements.send-pickup-instructions', $agreement))->assertOk();
        Notification::assertSentToTimes($agreement->user, AcceptanceItemAcceptedToUserNotification::class, 1);
        $this->assertSame($signedAt, $agreement->fresh()->signed_at->toIso8601String());
        $this->assertSame('original-signed.pdf', $agreement->fresh()->signed_pdf_path);
        $this->assertDatabaseHas('action_logs', [
            'item_type' => UserAgreement::class, 'item_id' => $agreement->id,
            'target_id' => $agreement->user_id, 'created_by' => $admin->id,
            'note' => 'Pickup scheduling instructions emailed to the agreement recipient.',
        ]);
        $this->postJson(route('api.user-agreements.send-pickup-instructions', $agreement), ['resend' => true])->assertOk();
        Notification::assertSentToTimes($agreement->user, AcceptanceItemAcceptedToUserNotification::class, 2);
    }

    public function test_send_action_requires_orders_edit_permission(): void
    {
        $agreement = $this->pickup();
        $this->actingAsForApi(User::factory()->create())
            ->postJson(route('api.user-agreements.send-pickup-instructions', $agreement))->assertForbidden();
        Notification::assertNothingSent();
    }

    public function test_unsigned_cancelled_and_non_pickup_agreements_are_refused(): void
    {
        $this->actingAsForApi(User::factory()->superuser()->create());
        $pending = $this->pickup(false);
        $this->postJson(route('api.user-agreements.send-pickup-instructions', $pending))->assertUnprocessable();
        foreach ([['agreement_type' => 'purchase'], ['lifecycle_stage' => 'cancelled'], ['terms_accepted_at' => now()]] as $changes) {
            $agreement = $this->pickup();
            $agreement->forceFill($changes)->saveQuietly();
            $this->postJson(route('api.user-agreements.send-pickup-instructions', $agreement))->assertUnprocessable();
        }
        Notification::assertNothingSent();
    }

    public function test_missing_booking_url_does_not_record_a_success(): void
    {
        $agreement = $this->pickup();
        config(['forms.pickup_auto_create.booking_url' => null]);
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.user-agreements.send-pickup-instructions', $agreement))->assertUnprocessable();
        $this->assertNull($agreement->fresh()->pickup_instructions_sent_at);
        $this->assertNull($agreement->fresh()->pickup_instructions_sending_at);
        Notification::assertNothingSent();
    }

    public function test_custom_body_keeps_booking_query_parameters(): void
    {
        $agreement = $this->pickup();
        config(['forms.pickup_auto_create.booking_url' => 'https://example.org/pickup?service=laptop&location=desk']);
        \App\Models\EmailTemplate::create(['key' => 'agreement.pickup_accepted', 'body' => 'Ready. [Schedule pickup]({{booking_url}})']);
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.user-agreements.send-pickup-instructions', $agreement))->assertOk()->assertJsonPath('status', 'success');
        Notification::assertSentToTimes($agreement->user, AcceptanceItemAcceptedToUserNotification::class, 1);
    }

    public function test_template_without_booking_link_is_not_sent_or_marked_successful(): void
    {
        $agreement = $this->pickup();
        \App\Models\EmailTemplate::create(['key' => 'agreement.pickup_accepted', 'body' => 'Your pickup is complete.']);
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.user-agreements.send-pickup-instructions', $agreement))->assertUnprocessable();
        $this->assertNull($agreement->fresh()->pickup_instructions_sent_at);
        $this->assertNull($agreement->fresh()->pickup_instructions_sending_at);
        Notification::assertNothingSent();
    }

    public function test_failed_send_releases_the_claim_and_can_be_retried(): void
    {
        $agreement = $this->pickup();
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.user-agreements.send-pickup-instructions', $agreement))->assertStatus(503);
        $this->assertNull($agreement->fresh()->pickup_instructions_sent_at);
        $this->assertNull($agreement->fresh()->pickup_instructions_sending_at);
        Notification::fake();
        $this->postJson(route('api.user-agreements.send-pickup-instructions', $agreement))->assertOk();
        Notification::assertSentToTimes($agreement->user, AcceptanceItemAcceptedToUserNotification::class, 1);
        $this->assertNotNull($agreement->fresh()->pickup_instructions_sent_at);
    }

    public function test_concurrent_claim_blocks_duplicate_send_but_stale_claim_can_retry(): void
    {
        $agreement = $this->pickup();
        $agreement->forceFill(['pickup_instructions_sending_at' => now()])->saveQuietly();
        $this->assertFalse(app(PickupInstructionsSender::class)->send($agreement));
        Notification::assertNothingSent();
        $agreement->forceFill(['pickup_instructions_sending_at' => now()->subMinutes(11)])->saveQuietly();
        $this->assertTrue(app(PickupInstructionsSender::class)->send($agreement));
        Notification::assertSentToTimes($agreement->user, AcceptanceItemAcceptedToUserNotification::class, 1);
    }

    public function test_admin_can_find_the_action_and_success_time_on_the_agreement(): void
    {
        $agreement = $this->pickup();
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('user-agreements.show', $agreement))->assertOk()->assertSee('Send pickup instructions');
        $this->post(route('user-agreements.send-pickup-instructions', $agreement))
            ->assertRedirect(route('user-agreements.show', $agreement))->assertSessionHasNoErrors();
        $this->get(route('user-agreements.show', $agreement))->assertOk()
            ->assertSee('Resend pickup instructions')->assertSee('Pickup instructions emailed');
    }
}
