<?php

namespace Tests\Feature\Deployments;

use App\Mail\DeploymentWaveMail;
use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentWave;
use App\Models\Location;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\UserAgreement;
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

    /**
     * The case that actually broke: a refresh wave's items carry only the device
     * being replaced, because the new machine has not been bought yet. Reading
     * `asset_id` alone found nobody in a wave of 21 named faculty.
     */
    public function test_a_planned_replacement_still_finds_the_person_on_the_old_device()
    {
        $wave = $this->wave();
        $faculty = User::factory()->create(['email' => 'faculty@ecuad.ca']);

        $old = Asset::factory()->create(['lease_end_date' => '2026-12-31']);
        $old->forceFill(['assigned_to' => $faculty->id, 'assigned_type' => User::class])->saveQuietly();

        // No asset_id: nothing has been ordered yet, which is the normal state of
        // a wave at the moment it is announced.
        DeploymentItem::create(['wave_id' => $wave->id, 'replaces_asset_id' => $old->id]);

        $recipients = (new WaveAnnouncer)->recipients($wave->fresh());

        $this->assertCount(1, $recipients);
        $this->assertSame('faculty@ecuad.ca', $recipients->first()['user']->email);
        $this->assertSame($old->id, $recipients->first()['assets']->first()->id);
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

    /**
     * A template default is rendered, not passed through trans(), so a ":token"
     * in one reaches the recipient verbatim — which is exactly what happened: a
     * test arrived titled "Faculty Laptop Program :fiscal_year".
     */
    public function test_the_shipped_templates_use_merge_fields_the_renderer_understands()
    {
        $wave = $this->wave();
        $faculty = User::factory()->create();
        $asset = $this->deviceFor($wave, $faculty);

        $announcer = new WaveAnnouncer;
        $row = $announcer->recipients($wave)->first();
        $context = $announcer->context($wave, $row['user'], $row['assets']);

        foreach (\App\Services\Deployments\WaveAnnouncementTemplates::all($wave) as $template) {
            $mail = new DeploymentWaveMail(
                $wave, $faculty, $template['subject'], $template['body'], $row['assets'], $context
            );

            $subject = $mail->envelope()->subject;

            $this->assertStringNotContainsString(':', str_replace(['https:', 'http:'], '', $subject),
                $template['key'].' subject should not carry a trans-style token');
            $this->assertStringNotContainsString('{{', $subject, $template['key'].' subject should be fully rendered');

            if ($template['body'] !== '') {
                $this->assertStringNotContainsString('{{', $mail->render(), $template['key'].' body should be fully rendered');
            }
        }

        // The subject says the calendar year, because "Faculty Laptop Program
        // FY2026-27" is not how anyone refers to it in an inbox.
        $this->assertStringContainsString(now()->format('Y'), (new DeploymentWaveMail(
            $wave, $faculty,
            trans('admin/deployments/general.announce_faculty_subject'),
            'x', $row['assets'], $context
        ))->envelope()->subject);
    }

    /**
     * The wording is composed in the browser, so "save this" has to write it
     * somewhere the next send reads — not wait for a code change a year later,
     * when whoever edited it is not in the room.
     */
    public function test_update_template_saves_the_wording_for_next_time()
    {
        Mail::fake();

        $wave = $this->wave();
        $this->deviceFor($wave, User::factory()->create());

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('deployment-waves.announce', $wave), [
                'subject' => 'Faculty Laptop Program {{ year }} — new laptop time!',
                'body' => 'Hello {{ first_name }}, this is the wording we settled on.',
                'save_template' => 1,
            ])
            ->assertRedirect(route('deployment-waves.show', $wave))
            ->assertSessionHas('success');

        // Saving is not sending.
        Mail::assertNothingSent();
        $this->assertNull($wave->fresh()->announced_at);

        $templates = \App\Services\Deployments\WaveAnnouncementTemplates::all($wave);
        $this->assertSame('saved', $templates[0]['key'], 'the saved wording should lead the picker');
        $this->assertStringContainsString('the wording we settled on', $templates[0]['body']);
        $this->assertSame('saved', \App\Services\Deployments\WaveAnnouncementTemplates::defaultKeyFor($wave));

        // And the shipped defaults are still reachable, so there is a way back.
        $this->assertContains('faculty_program', array_column($templates, 'key'));
    }

    /** A test can go to several people: an annual letter gets more than one read. */
    public function test_a_test_can_be_addressed_to_several_people()
    {
        Mail::fake();

        $wave = $this->wave();
        $this->deviceFor($wave, User::factory()->create(['email' => 'faculty@ecuad.ca']));

        $one = User::factory()->create(['email' => 'reviewer1@ecuad.ca']);
        $two = User::factory()->create(['email' => 'reviewer2@ecuad.ca']);

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('deployment-waves.announce', $wave), [
                'subject' => 'x', 'body' => 'y', 'test' => 1,
                'test_recipients' => [$one->id, $two->id],
            ])
            ->assertRedirect();

        Mail::assertSent(DeploymentWaveMail::class, fn ($mail) => $mail->test
            && $mail->hasTo('reviewer1@ecuad.ca') && $mail->hasTo('reviewer2@ecuad.ca')
            && ! $mail->hasTo('faculty@ecuad.ca'));

        $this->assertNull($wave->fresh()->announced_at);
    }

    /** People picked to be copied are copied on every one of the emails. */
    public function test_picked_people_are_copied_on_every_email()
    {
        Mail::fake();

        $wave = $this->wave();
        $this->deviceFor($wave, User::factory()->create(['email' => 'one@ecuad.ca']));
        $this->deviceFor($wave, User::factory()->create(['email' => 'two@ecuad.ca']));
        $dean = User::factory()->create(['email' => 'dean@ecuad.ca']);

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('deployment-waves.announce', $wave), [
                'subject' => 'x', 'body' => 'y', 'cc' => [$dean->id],
            ])
            ->assertRedirect();

        Mail::assertSent(DeploymentWaveMail::class, 2);
        Mail::assertSent(DeploymentWaveMail::class, fn ($mail) => $mail->hasCc('dean@ecuad.ca'));
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
            // The merged table is person-first for an assigned wave.
            ->assertSee(trans('admin/deployments/general.roster_person'));
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

    /** An application on file — the stamp only a real submission carries. */
    private function applied(User $user): UserAgreement
    {
        return UserAgreement::create([
            'agreement_type' => 'pickup',
            'user_id' => $user->id,
            'lifecycle_stage' => 'quoted',
            'terms_accepted_at' => now(),
        ]);
    }

    /**
     * Chasing the people who have not applied, without anyone working the
     * list out. Wave 2 went out to everyone and finding who had stalled
     * meant reading the ledger against the store orders by name.
     */
    public function test_the_not_applied_audience_skips_everyone_who_applied()
    {
        $wave = $this->wave();
        $done = User::factory()->create(['email' => 'done@ecuad.ca']);
        $stalled = User::factory()->create(['email' => 'stalled@ecuad.ca']);
        $this->deviceFor($wave, $done);
        $this->deviceFor($wave, $stalled);
        $this->applied($done);

        $chase = (new WaveAnnouncer)->recipients($wave, WaveAnnouncer::AUDIENCE_NO_APPLICATION);

        $this->assertSame(['stalled@ecuad.ca'], $chase->pluck('user.email')->all());
        // And the unfiltered send still reaches both.
        $this->assertCount(2, (new WaveAnnouncer)->recipients($wave));
    }

    /**
     * A row the lease-end pipeline wrote is not an application, so somebody
     * carrying one is still chased — that row is exactly why the store gate
     * checks terms_accepted_at rather than mere existence.
     */
    public function test_an_auto_created_pickup_does_not_count_as_applying()
    {
        $wave = $this->wave();
        $user = User::factory()->create(['email' => 'auto@ecuad.ca']);
        $this->deviceFor($wave, $user);

        UserAgreement::create([
            'agreement_type' => 'pickup',
            'user_id' => $user->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $chase = (new WaveAnnouncer)->recipients($wave, WaveAnnouncer::AUDIENCE_NO_APPLICATION);

        $this->assertSame(['auto@ecuad.ca'], $chase->pluck('user.email')->all());
    }

    /**
     * Chasing an order is only fair once somebody can place one, so this
     * audience is people who applied and stopped — never people who have
     * not started, who would be told to order from a store they cannot open.
     */
    public function test_the_not_ordered_audience_is_applicants_who_have_not_ordered()
    {
        $wave = $this->wave();
        $ordered = User::factory()->create(['email' => 'ordered@ecuad.ca']);
        $applied = User::factory()->create(['email' => 'applied@ecuad.ca']);
        $neverApplied = User::factory()->create(['email' => 'never@ecuad.ca']);
        $this->deviceFor($wave, $ordered);
        $this->deviceFor($wave, $applied);
        $this->deviceFor($wave, $neverApplied);

        $this->applied($ordered);
        $this->applied($applied);
        StoreOrder::create(['user_id' => $ordered->id, 'status' => 'pending']);

        $chase = (new WaveAnnouncer)->recipients($wave, WaveAnnouncer::AUDIENCE_NO_ORDER);

        $this->assertSame(['applied@ecuad.ca'], $chase->pluck('user.email')->all());
    }

    /** A withdrawn order is not an order, so they are chased again. */
    public function test_a_cancelled_order_does_not_count_as_ordering()
    {
        $wave = $this->wave();
        $user = User::factory()->create(['email' => 'withdrew@ecuad.ca']);
        $this->deviceFor($wave, $user);
        $this->applied($user);
        StoreOrder::create(['user_id' => $user->id, 'status' => 'cancelled']);

        $chase = (new WaveAnnouncer)->recipients($wave, WaveAnnouncer::AUDIENCE_NO_ORDER);

        $this->assertSame(['withdrew@ecuad.ca'], $chase->pluck('user.email')->all());
    }
}
