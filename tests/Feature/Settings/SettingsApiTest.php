<?php

namespace Tests\Feature\Settings;

use App\Enums\ActionType;
use App\Models\Actionlog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SettingsPages;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * The settings table over the API: GET/PATCH /api/v1/settings/{page}.
 */
class SettingsApiTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_the_index_lists_every_page_and_its_writable_keys()
    {
        $this->actingAsForApi($this->superuser())
            ->getJson(route('api.settings.index'))
            ->assertOk()
            ->assertJsonPath('pages.general.keys', fn ($keys) => in_array('dashboard_message', $keys, true))
            ->assertJsonPath('pages.ldap.write_only', ['ldap_pword', 'ldap_client_tls_key'])
            ->assertJsonStructure(['pages' => [
                'general', 'branding', 'security', 'localization', 'notifications', 'agreements',
                'slack', 'asset-tags', 'labels', 'ldap', 'saml', 'google', 'forms',
            ]]);
    }

    public function test_a_page_reads_back_what_is_stored()
    {
        $this->settings->set(['dashboard_message' => 'Hello', 'unique_serial' => 1, 'modellist_displays' => 'image,category']);

        $this->actingAsForApi($this->superuser())
            ->getJson(route('api.settings.pages.show', 'general'))
            ->assertOk()
            ->assertJsonPath('page', 'general')
            ->assertJsonPath('settings.dashboard_message', 'Hello')
            ->assertJsonPath('settings.unique_serial', true)
            ->assertJsonPath('settings.modellist_displays', ['image', 'category']);
    }

    public function test_every_page_accepts_its_own_values_back()
    {
        // A GET → PATCH round trip must never be refused: what a page reads
        // out is, by construction, something it will take back in. The SAML
        // page is left out because its metadata check reaches the network.
        $admin = $this->superuser();

        foreach (array_diff(SettingsPages::names(), ['saml']) as $page) {
            $settings = $this->actingAsForApi($admin)
                ->getJson(route('api.settings.pages.show', $page))
                ->assertOk()
                ->json('settings');

            $writable = array_filter($settings, fn ($key) => ! str_ends_with($key, '_set'), ARRAY_FILTER_USE_KEY);

            $this->actingAsForApi($admin)
                ->patchJson(route('api.settings.pages.update', $page), $writable)
                ->assertJsonPath('status', 'success');
        }
    }

    public function test_a_patch_changes_only_the_keys_sent_and_is_logged()
    {
        $this->settings->set(['dashboard_message' => 'Keep me', 'alert_email' => 'old@example.org']);
        $admin = $this->superuser();

        $this->actingAsForApi($admin)
            ->patchJson(route('api.settings.pages.update', 'notifications'), [
                'alert_email' => 'ops@example.org,it@example.org,',
                'alerts_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.settings.alert_email', 'ops@example.org,it@example.org')
            ->assertJsonPath('payload.settings.alerts_enabled', true);

        $setting = Setting::first();
        $this->assertSame('ops@example.org,it@example.org', $setting->alert_email);
        $this->assertSame('Keep me', $setting->dashboard_message);
        // The static settings cache is reset, so the next read is the row as saved.
        $this->assertSame('ops@example.org,it@example.org', Setting::getSettings()->alert_email);

        $log = Actionlog::where('item_type', Setting::class)->where('action_type', ActionType::Update->value)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, (int) $log->created_by);
        $this->assertSame(
            ['old' => 'old@example.org', 'new' => 'ops@example.org,it@example.org'],
            json_decode($log->log_meta, true)['alert_email'],
        );
    }

    public function test_a_secret_is_written_but_never_echoed()
    {
        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.pages.update', 'ldap'), [
                'ldap_pword' => 'S3cret-bind-pw',
                'ldap_uname' => 'binduser',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.settings.ldap_pword_set', true)
            ->assertJsonMissingPath('payload.settings.ldap_pword')
            ->assertDontSee('S3cret-bind-pw');

        $this->assertSame('S3cret-bind-pw', Crypt::decrypt(Setting::first()->ldap_pword));

        $this->actingAsForApi($this->superuser())
            ->getJson(route('api.settings.pages.show', 'ldap'))
            ->assertJsonPath('settings.ldap_pword_set', true)
            ->assertJsonMissingPath('settings.ldap_pword')
            ->assertDontSee('S3cret-bind-pw');

        // Nor does it reach the change log.
        $meta = Actionlog::where('item_type', Setting::class)->latest('id')->first()->log_meta;
        $this->assertStringNotContainsString('S3cret', $meta);
        $this->assertSame('*************', json_decode($meta, true)['ldap_pword']['new']);
    }

    public function test_an_unknown_key_refuses_the_whole_patch()
    {
        $this->settings->set(['dashboard_message' => 'Before']);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.pages.update', 'general'), [
                'dashboard_message' => 'After',
                'not_a_setting' => 1,
                'ldap_pword' => 'wrong page',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('messages.settings.0', fn ($message) => str_contains($message, 'not_a_setting') && str_contains($message, 'ldap_pword'));

        $this->assertSame('Before', Setting::first()->dashboard_message);
    }

    public function test_a_value_the_web_page_would_refuse_is_an_error()
    {
        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.pages.update', 'security'), ['pwd_secure_min' => 4])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => ['pwd_secure_min']]);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.pages.update', 'security'), ['pwd_secure_complexity' => ['letters', 'emoji']])
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => ['pwd_secure_complexity.1']]);
    }

    public function test_a_dependent_rule_is_judged_against_the_stored_row()
    {
        // Turning LDAP on with no server stored is refused even though the
        // server itself was not part of the PATCH.
        $this->settings->set(['ldap_server' => null]);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.pages.update', 'ldap'), ['ldap_enabled' => true])
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => ['ldap_server']]);
    }

    public function test_demo_locked_keys_are_refused()
    {
        config(['app.lock_passwords' => true]);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.pages.update', 'branding'), ['site_name' => 'Renamed'])
            ->assertJsonPath('status', 'error');

        $this->assertNotSame('Renamed', Setting::first()->site_name);
    }

    public function test_settings_pages_are_superuser_only()
    {
        $user = User::factory()->create();

        $this->actingAsForApi($user)
            ->getJson(route('api.settings.index'))
            ->assertForbidden();

        $this->actingAsForApi($user)
            ->getJson(route('api.settings.pages.show', 'general'))
            ->assertForbidden();

        $this->actingAsForApi($user)
            ->patchJson(route('api.settings.pages.update', 'general'), ['dashboard_message' => 'x'])
            ->assertForbidden();
    }

    public function test_an_unknown_page_is_not_found()
    {
        $this->actingAsForApi($this->superuser())
            ->getJson('/api/v1/settings/not-a-page')
            ->assertNotFound();
    }
}
