<?php

namespace Tests\Feature\Authentication;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Provider-agnostic OIDC bearer authentication for the API. Exercised through a
 * throwaway route under the real `auth:api,oidc` multi-guard, with JWTs signed
 * by a local RSA keypair and the JWKS seeded into the cache -- so these tests
 * never touch a network or a real IdP.
 */
class OidcApiAuthTest extends TestCase
{
    private string $issuer = 'https://issuer.test/v2.0';

    private string $audience = 'api://snipe-test';

    private string $jwksUri = 'https://issuer.test/discovery/keys';

    private string $kid = 'test-key-1';

    private string $privatePem;

    protected function setUp(): void
    {
        parent::setUp();

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $this->privatePem);
        $details = openssl_pkey_get_details($res);

        $jwks = ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $this->kid,
            'n' => $this->b64url($details['rsa']['n']),
            'e' => $this->b64url($details['rsa']['e']),
        ]]];

        config([
            'oidc.enabled' => true,
            'oidc.issuers' => [$this->issuer],
            'oidc.audiences' => [$this->audience],
            'oidc.jwks_uri' => $this->jwksUri,
            'oidc.algorithms' => ['RS256'],
            'oidc.username_claim' => 'preferred_username',
            'oidc.provision' => false,
            'oidc.leeway' => 60,
        ]);

        // Seed the signing keys so the validator never fetches over the network.
        Cache::put('oidc_jwks_'.md5($this->jwksUri), $jwks, 3600);

        Route::middleware('auth:api,oidc')->get('/_test/oidc-whoami', function () {
            return response()->json(['username' => auth()->user()->username]);
        });
    }

    private function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function token(array $overrides = []): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => 'subject-123',
            'preferred_username' => 'oidcuser',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
        ], $overrides);

        return JWT::encode($payload, $this->privatePem, 'RS256', $this->kid);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_valid_token_authenticates_the_matching_user()
    {
        User::factory()->create(['username' => 'oidcuser', 'activated' => 1]);

        $this->withHeaders($this->bearer($this->token()))
            ->getJson('/_test/oidc-whoami')
            ->assertOk()
            ->assertJson(['username' => 'oidcuser']);
    }

    public function test_bearer_is_ignored_when_oidc_is_disabled()
    {
        config(['oidc.enabled' => false]);
        User::factory()->create(['username' => 'oidcuser', 'activated' => 1]);

        $this->withHeaders($this->bearer($this->token()))
            ->getJson('/_test/oidc-whoami')
            ->assertUnauthorized();
    }

    public function test_unknown_user_is_rejected()
    {
        $this->withHeaders($this->bearer($this->token(['preferred_username' => 'ghost'])))
            ->getJson('/_test/oidc-whoami')
            ->assertUnauthorized();
    }

    public function test_deactivated_user_is_rejected()
    {
        User::factory()->create(['username' => 'oidcuser', 'activated' => 0]);

        $this->withHeaders($this->bearer($this->token()))
            ->getJson('/_test/oidc-whoami')
            ->assertUnauthorized();
    }

    public function test_wrong_audience_is_rejected()
    {
        User::factory()->create(['username' => 'oidcuser', 'activated' => 1]);

        $this->withHeaders($this->bearer($this->token(['aud' => 'api://someone-else'])))
            ->getJson('/_test/oidc-whoami')
            ->assertUnauthorized();
    }

    public function test_untrusted_issuer_is_rejected()
    {
        User::factory()->create(['username' => 'oidcuser', 'activated' => 1]);

        $this->withHeaders($this->bearer($this->token(['iss' => 'https://evil.test/v2.0'])))
            ->getJson('/_test/oidc-whoami')
            ->assertUnauthorized();
    }

    public function test_expired_token_is_rejected()
    {
        User::factory()->create(['username' => 'oidcuser', 'activated' => 1]);
        $past = time() - 300;

        $this->withHeaders($this->bearer($this->token(['iat' => $past, 'nbf' => $past, 'exp' => $past + 60])))
            ->getJson('/_test/oidc-whoami')
            ->assertUnauthorized();
    }

    public function test_malformed_bearer_is_rejected()
    {
        $this->withHeaders($this->bearer('not-a-jwt'))
            ->getJson('/_test/oidc-whoami')
            ->assertUnauthorized();
    }

    public function test_passport_token_still_authenticates_via_multiguard()
    {
        // The oidc guard must not break the existing Passport path: a route
        // under auth:api,oidc still authenticates a Passport-acting user.
        $user = User::factory()->create(['activated' => 1]);
        Passport::actingAs($user);

        $this->getJson('/_test/oidc-whoami')
            ->assertOk()
            ->assertJson(['username' => $user->username]);
    }
}
