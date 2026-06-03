<?php

namespace Tests\Feature\Settings;

use App\Mail\BaseMailable;
use App\Mail\EmailRegistry;
use App\Models\EmailTemplate;
use App\Models\User;
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
            // Notification-channel reports have no factory/preview yet (Phase E).
            if (! isset($email['factory'])) {
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

    public function test_notification_report_emails_are_listed_for_recipients(): void
    {
        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.emails.index'))
            ->assertOk();

        $response->assertSee('Expected checkin report');
        $response->assertSee('Low inventory report');
    }
}
