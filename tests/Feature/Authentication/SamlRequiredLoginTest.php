<?php

namespace Tests\Feature\Authentication;

use App\Models\User;
use App\Services\Saml;
use Tests\TestCase;

/**
 * When SAML is the required login path (REQUIRE_SAML env or the saml_forcelogin
 * setting), the local username/password form must never be shown to ordinary
 * users — not even after a SAML error — and local credential login is reserved
 * for the ?nosaml super-admin bypass.
 */
class SamlRequiredLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // /login redirects to the setup wizard until at least one user exists,
        // so seed one to mark setup complete before exercising the login flow.
        User::factory()->create();
    }

    public function test_login_redirects_to_saml_when_require_saml_is_set()
    {
        config(['app.require_saml' => true]);

        $this->get('/login')->assertRedirect(route('saml.login'));
    }

    public function test_saml_forcelogin_setting_redirects_to_saml()
    {
        // SAML is a configured-IdP singleton, so bind an enabled stub rather
        // than standing up real IdP metadata just to exercise the force-login
        // branch (this is the trigger our environment actually uses).
        $this->settings->set(['saml_forcelogin' => 1]);
        $this->instance(Saml::class, \Mockery::mock(Saml::class, ['isEnabled' => true]));

        $this->get('/login')->assertRedirect(route('saml.login'));
    }

    public function test_nosaml_bypass_renders_the_local_form()
    {
        config(['app.require_saml' => true]);

        $this->get('/login?nosaml=true')
            ->assertOk()
            ->assertSee('id="username"', false)
            ->assertSee('id="password-field"', false);
    }

    public function test_saml_error_does_not_expose_the_local_form()
    {
        config(['app.require_saml' => true]);

        // A user who authenticated with the IdP but isn't provisioned here ends
        // up back on /login with a flashed error. They must see the message and
        // a SAML retry — never the username/password fields.
        $this->withSession(['error' => 'There was a problem while trying to log you in'])
            ->get('/login')
            ->assertOk()
            ->assertDontSee('id="username"', false)
            ->assertDontSee('id="password-field"', false)
            ->assertSee(route('saml.login'), false);
    }

    public function test_local_login_post_is_denied_without_the_bypass()
    {
        config(['app.require_saml' => true]);
        User::factory()->create(['username' => 'owner']);

        $this->post('/login', ['username' => 'owner', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_local_login_post_is_allowed_with_the_bypass()
    {
        config(['app.require_saml' => true]);
        $owner = User::factory()->create(['username' => 'owner']);

        $this->post('/login?nosaml=true', ['username' => 'owner', 'password' => 'password']);

        $this->assertAuthenticatedAs($owner);
    }

    public function test_normal_login_form_is_unaffected_when_saml_not_required()
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('id="username"', false);
    }
}
