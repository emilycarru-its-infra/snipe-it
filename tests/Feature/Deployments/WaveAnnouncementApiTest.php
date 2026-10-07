<?php

namespace Tests\Feature\Deployments;

use App\Mail\DeploymentWaveMail;
use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentWave;
use App\Models\User;
use App\Models\UserAgreement;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Emailing the people in a wave over the API.
 *
 * The board's form was the only way to send, so a chase that a script had
 * already worked out still needed somebody in a browser to press the button.
 * The API goes through the same WaveAnnouncer::announce() as the form, so the
 * gate, the audiences and the "nobody to chase" answer are identical.
 */
class WaveAnnouncementApiTest extends TestCase
{
    private function wave(): DeploymentWave
    {
        return DeploymentWave::create([
            'name' => 'Faculty Laptop Program refresh FY2026-27',
            'slug' => 'faculty-laptop-program-refresh-fy2026-27-'.uniqid(),
            'fiscal_year' => 'FY2026-27',
            'wave_state' => 'planned',
        ]);
    }

    private function holder(DeploymentWave $wave, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $asset = Asset::factory()->create(['lease_end_date' => '2026-12-31']);
        $asset->forceFill(['assigned_to' => $user->id, 'assigned_type' => User::class])->saveQuietly();
        DeploymentItem::create(['wave_id' => $wave->id, 'replaces_asset_id' => $asset->id]);

        return $user;
    }

    private function applied(User $user): void
    {
        UserAgreement::create([
            'agreement_type' => 'pickup',
            'user_id' => $user->id,
            'lifecycle_stage' => 'quoted',
            'terms_accepted_at' => now(),
        ]);
    }

    public function test_the_preview_lists_templates_and_who_each_audience_reaches()
    {
        $wave = $this->wave();
        $done = $this->holder($wave, 'done@example.com');
        $this->holder($wave, 'stalled@example.com');
        $this->applied($done);

        $payload = $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.deployments.waves.announce.show', $wave))
            ->assertOk()
            ->json('payload');

        $this->assertSame('faculty_program', $payload['default_template']);
        $this->assertContains('faculty_program', array_column($payload['templates'], 'key'));
        $this->assertSame(2, $payload['audiences']['all']['count']);
        $this->assertSame(['stalled@example.com'], array_column($payload['audiences']['no_application']['recipients'], 'email'));
        $this->assertSame(['done@example.com'], array_column($payload['audiences']['no_order']['recipients'], 'email'));
    }

    public function test_a_chase_on_a_template_reaches_only_its_audience_and_starts_the_wave()
    {
        Mail::fake();

        $wave = $this->wave();
        $done = $this->holder($wave, 'done@example.com');
        $this->holder($wave, 'stalled@example.com');
        $this->applied($done);

        $response = $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.deployments.waves.announce', $wave), [
                'template' => 'faculty_program',
                'audience' => 'no_application',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('payload');

        $this->assertSame(1, $response['sent']);
        $this->assertSame(['stalled@example.com'], $response['recipients']);
        Mail::assertSent(DeploymentWaveMail::class, fn ($mail) => $mail->hasTo('stalled@example.com'));
        Mail::assertNotSent(DeploymentWaveMail::class, fn ($mail) => $mail->hasTo('done@example.com'));
        $this->assertNotNull($wave->fresh()->announced_at);
    }

    public function test_a_test_send_goes_to_the_caller_only()
    {
        Mail::fake();

        $wave = $this->wave();
        $this->holder($wave, 'faculty@example.com');
        $staff = User::factory()->superuser()->create();

        $this->actingAsForApi($staff)
            ->postJson(route('api.deployments.waves.announce', $wave), [
                'subject' => 'Faculty Laptop Program',
                'body' => 'Hello {{ first_name }}.',
                'test' => true,
            ])
            ->assertOk()
            ->assertJsonPath('payload.recipients', [$staff->email]);

        Mail::assertNotSent(DeploymentWaveMail::class, fn ($mail) => $mail->hasTo('faculty@example.com'));
        $this->assertNull($wave->fresh()->announced_at);
    }

    public function test_nobody_to_chase_is_a_success_that_sends_nothing()
    {
        Mail::fake();

        $wave = $this->wave();
        $this->applied($this->holder($wave, 'done@example.com'));

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.deployments.waves.announce', $wave), [
                'template' => 'faculty_program',
                'audience' => 'no_application',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.sent', 0);

        Mail::assertNothingSent();
    }

    public function test_the_blank_template_alone_is_refused()
    {
        $wave = $this->wave();
        $this->holder($wave, 'faculty@example.com');

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.deployments.waves.announce', $wave), ['template' => 'blank'])
            ->assertStatus(422);
    }

    public function test_sending_needs_manage_permission()
    {
        $wave = $this->wave();

        $this->actingAsForApi(User::factory()->create())
            ->postJson(route('api.deployments.waves.announce', $wave), ['template' => 'faculty_program'])
            ->assertForbidden();

        $this->actingAsForApi(User::factory()->create())
            ->getJson(route('api.deployments.waves.announce.show', $wave))
            ->assertForbidden();
    }
}
