<?php

namespace Tests\Feature\Deployments;

use App\Mail\DeploymentWaveMail;
use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentWave;
use App\Models\Location;
use App\Models\User;
use App\Services\Deployments\WaveAnnouncer;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Announcing a wave to the people in it.
 *
 * For the Faculty Laptop Program this email is the first step of the cycle — it
 * tells faculty the year is open and where to say yes — so sending it starts the
 * wave. The other invariants are about who hears it and what they see: one email
 * per person, written against their own device, and a test that goes only to the
 * sender.
 */
class WaveAnnouncementTest extends TestCase
{
    private function wave(array $overrides = []): DeploymentWave
    {
        return DeploymentWave::create(array_merge([
            'name' => 'Faculty Laptop Program refresh FY2026-27',
            'slug' => 'faculty-laptop-program-refresh-fy2026-27-'.uniqid(),
            'fiscal_year' => 'FY2026-27',
            'wave_state' => 'planned',
        ], $overrides));
    }

    private function deviceFor(DeploymentWave $wave, ?User $holder, ?Location $location = null): Asset
    {
        $asset = Asset::factory()->create(['lease_end_date' => '2026-12-31']);

        if ($holder) {
            $asset->forceFill(['assigned_to' => $holder->id, 'assigned_type' => User::class])->saveQuietly();
        } elseif ($location) {
            $asset->forceFill(['assigned_to' => $location->id, 'assigned_type' => Location::class])->saveQuietly();
        }

        DeploymentItem::create(['wave_id' => $wave->id, 'asset_id' => $asset->id]);

        return $asset->refresh();
    }

    public function test_recipients_come_from_the_devices_not_a_list()
    {
        $wave = $this->wave();
        $faculty = User::factory()->create(['email' => 'faculty@ecuad.ca']);

        // Two devices, one person: one email, carrying both.
        $this->deviceFor($wave, $faculty);
        $this->deviceFor($wave, $faculty);

        // A lab machine has no inbox, so it contributes no recipient.
        $this->deviceFor($wave, null, Location::factory()->create());

        // And an unassigned device contributes nobody either.
        $this->deviceFor($wave, null);

        $recipients = (new WaveAnnouncer)->recipients($wave->fresh());

        $this->assertCount(1, $recipients);
        $this->assertSame('faculty@ecuad.ca', $recipients->first()['user']->email);
        $this->assertCount(2, $recipients->first()['assets']);
    }

    public function test_a_test_send_goes_only_to_the_sender_and_does_not_start_the_wave()
    {
        Mail::fake();

        $wave = $this->wave();
        $this->deviceFor($wave, User::factory()->create(['email' => 'faculty@ecuad.ca']));
        $staff = User::factory()->superuser()->create();

        $this->actingAs($staff)
            ->post(route('deployment-waves.announce', $wave), [
                'subject' => 'Faculty Laptop Program FY2026-27',
                'body' => 'Hello {{ first_name }}, your device is {{ device }}.',
                'test' => 1,
            ])
            ->assertRedirect(route('deployment-waves.show', $wave));

        Mail::assertSent(DeploymentWaveMail::class, fn ($mail) => $mail->test && $mail->hasTo($staff->email));
        Mail::assertNotSent(DeploymentWaveMail::class, fn ($mail) => $mail->hasTo('faculty@ecuad.ca'));

        $wave->refresh();
        $this->assertNull($wave->announced_at);
        $this->assertSame('planned', $wave->wave_state);
    }

    /**
     * The point of the feature: the email is what starts a program wave, so
     * sending it is what moves the wave off planned.
     */
    public function test_a_real_send_reaches_everyone_and_starts_the_wave()
    {
        Mail::fake();

        $wave = $this->wave();
        $this->deviceFor($wave, User::factory()->create(['email' => 'one@ecuad.ca']));
        $this->deviceFor($wave, User::factory()->create(['email' => 'two@ecuad.ca']));

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('deployment-waves.announce', $wave), [
                'subject' => 'Faculty Laptop Program FY2026-27',
                'body' => 'Hello {{ first_name }}, your device is {{ device }} and its lease ends {{ lease_end }}.',
            ])
            ->assertRedirect();

        Mail::assertSent(DeploymentWaveMail::class, 2);
        Mail::assertSent(DeploymentWaveMail::class, fn ($mail) => $mail->hasTo('one@ecuad.ca'));
        Mail::assertSent(DeploymentWaveMail::class, fn ($mail) => $mail->hasTo('two@ecuad.ca'));

        $wave->refresh();
        $this->assertNotNull($wave->announced_at);
        $this->assertSame('ordered', $wave->wave_state, 'sending the announcement starts the wave');
    }

    /** Each person's copy is about their own machine, not a shared broadcast. */
    public function test_the_body_is_rendered_against_each_persons_own_device()
    {
        $wave = $this->wave();
        $faculty = User::factory()->create(['first_name' => 'Ada', 'email' => 'ada@ecuad.ca']);
        $asset = $this->deviceFor($wave, $faculty);

        $announcer = new WaveAnnouncer;
        $row = $announcer->recipients($wave)->first();
        $context = $announcer->context($wave, $row['user'], $row['assets']);

        $rendered = (new DeploymentWaveMail(
            $wave, $faculty,
            'Faculty Laptop Program {{ fiscal_year }}',
            'Hello {{ first_name }}, you hold {{ device }} and its lease ends {{ lease_end }}. Form: {{ form_url }}',
            $row['assets'], $context
        ))->render();

        $this->assertStringContainsString('Ada', $rendered);
        $this->assertStringContainsString($asset->asset_tag, $rendered);
        $this->assertStringContainsString('December 31, 2026', $rendered);
        $this->assertStringContainsString('faculty-program', $rendered);
        $this->assertStringNotContainsString('{{', $rendered, 'no merge field should survive rendering');
    }

    public function test_a_wave_with_nobody_holding_a_device_cannot_be_announced()
    {
        Mail::fake();

        $wave = $this->wave();
        $this->deviceFor($wave, null, Location::factory()->create());

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('deployment-waves.announce', $wave), ['subject' => 'x', 'body' => 'y'])
            ->assertSessionHas('error');

        Mail::assertNotSent(DeploymentWaveMail::class);
        $this->assertNull($wave->fresh()->announced_at);
    }

    public function test_a_stranger_cannot_announce_a_wave()
    {
        Mail::fake();

        $wave = $this->wave();
        $this->deviceFor($wave, User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->post(route('deployment-waves.announce', $wave), ['subject' => 'x', 'body' => 'y'])
            ->assertForbidden();

        Mail::assertNotSent(DeploymentWaveMail::class);
    }

    /** The wave page offers the annual letter, prefilled, and the recipient list. */
    public function test_the_wave_page_offers_the_faculty_template_and_names_the_recipients()
    {
        $wave = $this->wave();
        $this->deviceFor($wave, User::factory()->create(['email' => 'faculty@ecuad.ca']));

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('deployment-waves.show', $wave))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.announce_title'))
            ->assertSee('Faculty Laptop Program', false)
            ->assertSee('faculty@ecuad.ca')
            ->assertSee(trans('admin/deployments/general.checked_out_to'));
    }

    /** A wave lives under deployments; the old top-level path still lands. */
    public function test_wave_urls_live_under_deployments()
    {
        $wave = $this->wave();

        $this->assertSame('/deployments/waves/'.$wave->id, route('deployment-waves.show', $wave, false));

        $this->actingAs(User::factory()->superuser()->create())
            ->get('/deployment-waves/'.$wave->id)
            ->assertRedirect('/deployments/waves/'.$wave->id);
    }
}
