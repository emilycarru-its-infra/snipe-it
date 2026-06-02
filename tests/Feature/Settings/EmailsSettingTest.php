<?php

namespace Tests\Feature\Settings;

use App\Mail\EmailRegistry;
use App\Models\User;
use Tests\TestCase;

class EmailsSettingTest extends TestCase
{
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
}
