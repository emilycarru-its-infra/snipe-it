<?php

namespace Tests\Feature\Authentication;

use App\Models\User;
use App\Services\Saml;
use Tests\TestCase;

/**
 * When SAML is the required login path the local username/password form must
 * never be shown to ordinary users — not even after a SAML error. Two modes:
 *
 *  - REQUIRE_SAML (env): a hard lock. No local form, no ?nosaml workaround
 *    (config/app.php documents that it disables the bypass).
 *  - saml_forcelogin (setting): routes everyone to the IdP, but the owner can
 *    still reach the local form via the ?nosaml super-admin bypass.
 */
class SamlRequiredLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // /login redirects to the setup wizard until at least one user exists.
        User::factory()->create();
    }

    /** Turn on saml_forcelogin with an enabled SAML stub (no real IdP needed). */
    private function forceSamlLogin(): void
    {
        $this->settings->set(['saml_forcelogin' => 1]);
        $this->instance(Saml::class, \Mockery::mock(Saml::class, ['isEnabled' => true]));
    }

    // --- REQUIRE_SAML: hard lock, no bypass ---------------------------------

    public function test_require_saml_redirects_to_saml()
    {
        config(['app.require_saml' => true]);

        $this->get('/login')->assertRedirect(route('saml.login'));
    }

    public function test_require_saml_has_no_nosaml_bypass()
    {
        config(['app.require_saml' => true]);

        // The hard lock ignores ?nosaml — still redirected, no local form.
        $this->get('/login?nosaml=true')->assertRedirect(route('saml.login'));
    }

    public function test_require_saml_post_is_denied_with_403_even_with_nosaml()
    {
        config(['app.require_saml' => true]);
        User::factory()->superuser()->create(['username' => 'owner']);

        $this->post('/login?nosaml=true', ['username' => 'owner', 'password' => 'password'])
            ->assertForbidden();
        $this->assertGuest();
    }

    public function test_require_saml_error_does_not_expose_the_local_form()
    {
        config(['app.require_saml' => true]);

        $this->withSession(['error' => 'There was a problem while trying to log you in'])
            ->get('/login')
            ->assertOk()
            ->assertDontSee('id="username"', false)
            ->assertSee(route('saml.login'), false);
    }

    public function test_unprovisioned_user_sees_friendly_message_not_the_form()
    {
        config(['app.require_saml' => true]);

        $this->withSession(['warning' => trans('auth/message.signin.account_not_provisioned')])
            ->get('/login')
            ->assertOk()
            ->assertDontSee('id="username"', false)
            ->assertSee(trans('auth/message.signin.account_not_provisioned'));
    }

    // --- saml_forcelogin: redirect, with ?nosaml owner bypass ---------------

    public function test_saml_forcelogin_redirects_to_saml()
    {
        $this->forceSamlLogin();

        $this->get('/login')->assertRedirect(route('saml.login'));
    }

    public function test_forcelogin_nosaml_bypass_renders_the_local_form()
    {
        $this->forceSamlLogin();

        $this->get('/login?nosaml=true')
            ->assertOk()
            ->assertSee('id="username"', false)
            ->assertSee('id="password-field"', false);
    }

    public function test_forcelogin_post_is_denied_with_403_without_the_bypass()
    {
        $this->forceSamlLogin();
        User::factory()->create(['username' => 'someone']);

        $this->post('/login', ['username' => 'someone', 'password' => 'password'])
            ->assertForbidden();
        $this->assertGuest();
    }

    public function test_forcelogin_post_is_allowed_for_owner_via_the_bypass()
    {
        $this->forceSamlLogin();
        $owner = User::factory()->superuser()->create(['username' => 'owner']);

        $this->post('/login?nosaml=true', ['username' => 'owner', 'password' => 'password']);

        $this->assertAuthenticatedAs($owner);
    }

    // --- No SAML enforcement: vanilla form -----------------------------------

    public function test_normal_login_form_is_unaffected_when_saml_not_required()
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('id="username"', false);
    }
}
