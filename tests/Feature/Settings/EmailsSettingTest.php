<?php

namespace Tests\Feature\Settings;

use App\Mail\BaseMailable;
use App\Mail\EmailRegistry;
use App\Mail\CheckoutAssetMail;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Notifications\InventoryAlert;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailsSettingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The subject-override cache is static and would leak across tests.
        BaseMailable::flushSubjectCache();
        BaseMailable::$ignoreOverrides = false;
    }

    private function defaultSubject(string $key): string
    {
        BaseMailable::$ignoreOverrides = true;
        $subject = (string) EmailRegistry::makeMailable($key)->envelope()->subject;
        BaseMailable::$ignoreOverrides = false;

        return $subject;
    }

    public function test_hub_is_gated_to_superusers(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.emails.index'))
            ->assertForbidden();
    }

    public function test_hub_lists_every_registered_email(): void
    {
        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.emails.index'))
            ->assertOk();

        foreach (EmailRegistry::all() as $email) {
            $response->assertSee($email['label']);
        }
    }

    public function test_every_registered_email_previews_with_sample_data(): void
    {
        $admin = User::factory()->superuser()->create();
        $errorText = trans('admin/settings/general.emails_preview_error');
        $failed = [];

        foreach (EmailRegistry::all() as $email) {
            // Covers both mailable and notification-channel emails.
            if (! EmailRegistry::isPreviewable($email)) {
                continue;
            }

            $content = $this->actingAs($admin)
                ->get(route('settings.emails.preview', $email['key']))
                ->assertOk()
                ->getContent();

            if ($content === '' || str_contains($content, $errorText)) {
                $failed[] = $email['key'];
            }
        }

        // The controller swallows render failures into a placeholder, so a broken
        // sample factory shows up here rather than as a 500.
        $this->assertSame([], $failed, 'Emails that failed to preview: '.implode(', ', $failed));
    }

    public function test_unknown_email_key_returns_404(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.emails.preview', 'does.not.exist'))
            ->assertNotFound();
    }

    public function test_saving_a_subject_is_gated_to_superusers(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('settings.emails.save'), ['key' => 'checkout.asset', 'subject' => 'Nope'])
            ->assertForbidden();
    }

    public function test_subject_override_is_saved_and_used(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), ['key' => 'checkout.asset', 'subject' => 'Your ECU device is ready'])
            ->assertRedirect(route('settings.emails.index', ['selected' => 'checkout.asset']));

        $this->assertDatabaseHas('email_templates', [
            'key' => 'checkout.asset',
            'subject' => 'Your ECU device is ready',
        ]);

        BaseMailable::flushSubjectCache();
        $this->assertSame(
            'Your ECU device is ready',
            (string) EmailRegistry::makeMailable('checkout.asset')->envelope()->subject,
        );
    }

    public function test_blank_subject_clears_the_override_and_falls_back_to_default(): void
    {
        $default = $this->defaultSubject('checkin.asset');
        EmailTemplate::create(['key' => 'checkin.asset', 'subject' => 'Custom checkin subject']);

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), ['key' => 'checkin.asset', 'subject' => '']);

        $this->assertDatabaseHas('email_templates', ['key' => 'checkin.asset', 'subject' => null]);

        BaseMailable::flushSubjectCache();
        $this->assertSame(
            $default,
            (string) EmailRegistry::makeMailable('checkin.asset')->envelope()->subject,
        );
    }

    public function test_body_override_is_saved_and_rendered(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), [
                'key' => 'checkout.asset',
                'body' => "# Custom heading for {{item.asset_tag}}\n\nHello {{target}}.",
            ])
            ->assertRedirect(route('settings.emails.index', ['selected' => 'checkout.asset']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('email_templates', ['key' => 'checkout.asset']);

        $html = EmailRegistry::makeMailable('checkout.asset')->render();
        $this->assertStringContainsString('Custom heading for', $html);
        // Merge variable resolved against the sample data.
        $this->assertStringContainsString('ECU-100123', $html);
    }

    public function test_blank_body_clears_the_override_and_falls_back_to_default(): void
    {
        EmailTemplate::create(['key' => 'checkin.asset', 'body' => '# A custom body {{item.asset_tag}}']);

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), ['key' => 'checkin.asset', 'body' => '']);

        $this->assertDatabaseHas('email_templates', ['key' => 'checkin.asset', 'body' => null]);

        $html = EmailRegistry::makeMailable('checkin.asset')->render();
        $this->assertStringNotContainsString('A custom body', $html);
        // The built-in template still renders.
        $this->assertStringContainsString('ECU-100123', $html);
    }

    public function test_invalid_body_template_is_rejected(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->from(route('settings.emails.index'))
            ->post(route('settings.emails.save'), ['key' => 'checkout.asset', 'body' => '{{#each items}} never closed'])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseMissing('email_templates', ['key' => 'checkout.asset']);
    }

    public function test_recipients_override_is_saved_and_resolved(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), [
                'key' => 'report.expiring_assets',
                'recipients' => 'a@ecuad.ca, b@ecuad.ca',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('email_templates', [
            'key' => 'report.expiring_assets',
            'recipients' => 'a@ecuad.ca,b@ecuad.ca',
        ]);

        $this->assertSame(
            ['a@ecuad.ca', 'b@ecuad.ca'],
            EmailTemplate::recipientsFor('report.expiring_assets', 'fallback@ecuad.ca'),
        );
    }

    public function test_recipients_resolver_falls_back_to_global_list_when_unset(): void
    {
        $this->assertSame(
            ['ops@ecuad.ca', 'team@ecuad.ca'],
            EmailTemplate::recipientsFor('report.upcoming_audits', 'ops@ecuad.ca, team@ecuad.ca'),
        );
    }

    public function test_invalid_recipient_email_is_rejected(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->from(route('settings.emails.index'))
            ->post(route('settings.emails.save'), [
                'key' => 'report.expiring_assets',
                'recipients' => 'a@ecuad.ca, not-an-email',
            ])
            ->assertSessionHasErrors('recipients');

        $this->assertDatabaseMissing('email_templates', ['key' => 'report.expiring_assets']);
    }

    public function test_recipients_can_be_saved_as_an_array_from_the_picker(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), [
                'key' => 'report.expiring_assets',
                // The multi-select posts an array; duplicates collapse.
                'recipients' => ['a@ecuad.ca', 'b@ecuad.ca', 'a@ecuad.ca'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['a@ecuad.ca', 'b@ecuad.ca'],
            EmailTemplate::recipientsFor('report.expiring_assets', 'fallback@ecuad.ca'),
        );
    }

    public function test_cc_override_is_saved_and_resolved(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), [
                'key' => 'request.asset_buyout',
                'cc' => 'hr@ecuad.ca, finance@ecuad.ca',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('email_templates', [
            'key' => 'request.asset_buyout',
            'cc' => 'hr@ecuad.ca,finance@ecuad.ca',
        ]);

        $this->assertSame(
            ['hr@ecuad.ca', 'finance@ecuad.ca'],
            EmailTemplate::ccFor('request.asset_buyout', 'fallback@ecuad.ca'),
        );
    }

    public function test_cc_resolver_falls_back_to_default_list_when_unset(): void
    {
        $this->assertSame(
            ['devicesadmins@ecuad.ca', 'rdatta@ecuad.ca'],
            EmailTemplate::ccFor('request.asset_buyout', 'devicesadmins@ecuad.ca,rdatta@ecuad.ca'),
        );
    }

    public function test_invalid_cc_email_is_rejected(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->from(route('settings.emails.index'))
            ->post(route('settings.emails.save'), [
                'key' => 'request.asset_buyout',
                'cc' => 'hr@ecuad.ca, not-an-email',
            ])
            ->assertSessionHasErrors('cc');

        $this->assertDatabaseMissing('email_templates', ['key' => 'request.asset_buyout']);
    }

    public function test_blank_cc_clears_the_override(): void
    {
        EmailTemplate::create(['key' => 'request.asset_buyout', 'cc' => 'hr@ecuad.ca']);

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), [
                'key' => 'request.asset_buyout',
                'cc' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['fallback@ecuad.ca'],
            EmailTemplate::ccFor('request.asset_buyout', 'fallback@ecuad.ca'),
        );
    }

    public function test_recipient_options_endpoint_searches_users(): void
    {
        $admin = User::factory()->superuser()->create();
        User::factory()->create(['first_name' => 'Zelda', 'last_name' => 'Fitzpatrick', 'email' => 'zelda@ecuad.ca']);

        $this->actingAs($admin)
            ->getJson(route('settings.emails.recipient-options', ['search' => 'Zelda']))
            ->assertOk()
            ->assertJsonFragment(['id' => 'zelda@ecuad.ca'])
            ->assertJsonPath('pagination.more', false);
    }

    public function test_recipient_options_endpoint_is_gated_to_superusers(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.emails.recipient-options'))
            ->assertForbidden();
    }

    public function test_saved_recipients_are_exposed_as_labelled_picker_options(): void
    {
        $user = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Quon', 'email' => 'pat@ecuad.ca']);
        EmailTemplate::create(['key' => 'report.expiring_assets', 'recipients' => 'pat@ecuad.ca,list@ecuad.ca']);

        $content = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.emails.index'))
            ->assertOk()
            ->getContent();

        // The user address is labelled with their name; a non-user address shows
        // as itself. Both are emitted as picker options (data-recipients-json).
        $this->assertStringContainsString('pat@ecuad.ca', $content);
        $this->assertStringContainsString('list@ecuad.ca', $content);
        $this->assertStringContainsString($user->display_name, $content);
    }

    public function test_report_body_override_renders_an_each_loop(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), [
                'key' => 'report.expiring_assets',
                'body' => "# {{threshold}}-day warranty digest\n\n{{#each assets}}- {{this.asset_tag}}\n{{/each}}",
            ])
            ->assertSessionHasNoErrors();

        $html = EmailRegistry::makeMailable('report.expiring_assets')->render();
        $this->assertStringContainsString('60-day warranty digest', $html);
        // The sample digest has 3 assets — the loop should emit a row for each.
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'ECU-1001'));
    }

    public function test_notification_report_emails_are_listed_for_recipients(): void
    {
        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.emails.index'))
            ->assertOk();

        $response->assertSee('Expected checkin report');
        $response->assertSee('Low inventory report');
    }

    public function test_blank_recipients_clears_the_override(): void
    {
        EmailTemplate::create(['key' => 'report.expiring_assets', 'recipients' => 'x@ecuad.ca']);

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), ['key' => 'report.expiring_assets', 'recipients' => '']);

        $this->assertDatabaseHas('email_templates', ['key' => 'report.expiring_assets', 'recipients' => null]);
    }

    public function test_hub_shows_who_last_edited_an_override(): void
    {
        $admin = User::factory()->superuser()->create(['first_name' => 'Edna', 'last_name' => 'Editor']);

        $this->actingAs($admin)
            ->post(route('settings.emails.save'), ['key' => 'checkout.asset', 'subject' => 'Hi there']);

        $this->actingAs($admin)
            ->get(route('settings.emails.index'))
            ->assertOk()
            ->assertSee('Edna Editor');
    }

    public function test_test_send_emails_the_current_admin(): void
    {
        Mail::fake();
        $admin = User::factory()->superuser()->create(['email' => 'me@ecuad.ca']);

        $this->actingAs($admin)
            ->post(route('settings.emails.test'), ['key' => 'checkout.asset'])
            ->assertRedirect(route('settings.emails.index', ['selected' => 'checkout.asset']))
            ->assertSessionHas('success');

        Mail::assertSent(CheckoutAssetMail::class);
    }

    public function test_test_send_is_unavailable_for_an_unknown_key(): void
    {
        Mail::fake();

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.test'), ['key' => 'does.not.exist'])
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    // ---- Notification-channel emails are editable (Phase F) ----

    public function test_notification_email_is_editable_with_a_default_subject(): void
    {
        $entry = EmailRegistry::find('report.low_inventory');
        $this->assertTrue(EmailRegistry::isEditable($entry), 'A notification email should be editable.');

        BaseMailable::$ignoreOverrides = true;
        $this->assertNotSame('', EmailRegistry::defaultSubject('report.low_inventory'));
        BaseMailable::$ignoreOverrides = false;
    }

    public function test_notification_subject_override_is_saved_and_used(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), ['key' => 'report.low_inventory', 'subject' => 'Stock is running low'])
            ->assertRedirect(route('settings.emails.index', ['selected' => 'report.low_inventory']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('email_templates', ['key' => 'report.low_inventory', 'subject' => 'Stock is running low']);

        [$notification, $notifiable] = EmailRegistry::makeNotification('report.low_inventory');
        $this->assertSame('Stock is running low', (string) $notification->toMail($notifiable)->subject);
    }

    public function test_notification_subject_falls_back_to_default_when_blank(): void
    {
        BaseMailable::$ignoreOverrides = true;
        $default = EmailRegistry::defaultSubject('report.low_inventory');
        BaseMailable::$ignoreOverrides = false;

        EmailTemplate::create(['key' => 'report.low_inventory', 'subject' => 'Custom']);
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), ['key' => 'report.low_inventory', 'subject' => '']);

        [$notification, $notifiable] = EmailRegistry::makeNotification('report.low_inventory');
        $this->assertSame($default, (string) $notification->toMail($notifiable)->subject);
    }

    public function test_notification_body_override_is_saved_and_rendered(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), [
                'key' => 'report.low_inventory',
                'body' => "# Stock alert heading\n\n{{#each items}}- {{name}}: {{remaining}}/{{min_amt}}\n{{/each}}",
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('email_templates', ['key' => 'report.low_inventory']);

        // renderPreview applies the saved override against the sample items.
        $html = EmailRegistry::renderPreview('report.low_inventory');
        $this->assertStringContainsString('Stock alert heading', $html);
        $this->assertStringContainsString('Toner Cartridge (Black)', $html);
    }

    public function test_notification_blank_body_falls_back_to_built_in_view(): void
    {
        EmailTemplate::create(['key' => 'report.low_inventory', 'body' => '# A custom body']);
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), ['key' => 'report.low_inventory', 'body' => '']);

        $this->assertDatabaseHas('email_templates', ['key' => 'report.low_inventory', 'body' => null]);

        $html = EmailRegistry::renderPreview('report.low_inventory');
        $this->assertStringNotContainsString('A custom body', $html);
        // The built-in low-inventory table still renders the sample rows.
        $this->assertStringContainsString('Toner Cartridge (Black)', $html);
    }

    public function test_test_send_dispatches_a_notification(): void
    {
        Notification::fake();
        $admin = User::factory()->superuser()->create(['email' => 'me@ecuad.ca']);

        $this->actingAs($admin)
            ->post(route('settings.emails.test'), ['key' => 'report.low_inventory'])
            ->assertRedirect(route('settings.emails.index', ['selected' => 'report.low_inventory']))
            ->assertSessionHas('success');

        Notification::assertSentTo($admin, InventoryAlert::class);
    }

    public function test_test_send_relay_failure_flashes_error_not_500(): void
    {
        $admin = User::factory()->superuser()->create(['email' => 'me@ecuad.ca']);

        // Simulate the SMTP relay rejecting the message (e.g. an external
        // recipient the relay won't deliver to). The hub must stay usable.
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('Relay access denied'));

        $this->actingAs($admin)
            ->post(route('settings.emails.test'), ['key' => 'checkout.asset'])
            ->assertRedirect(route('settings.emails.index', ['selected' => 'checkout.asset']))
            ->assertSessionHas('error');
    }
}
