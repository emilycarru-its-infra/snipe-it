<?php

namespace App\Services\Oidc;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Maps a validated OIDC token's claims to an existing Snipe-IT user. The
 * matched user's own permissions then apply unchanged -- this class grants no
 * privileges of its own.
 *
 * Lookup mirrors the SAML path (by username, active + not soft-deleted). Unknown
 * users are rejected unless just-in-time provisioning is explicitly enabled, in
 * which case they are created with no extra permissions.
 */
class OidcUserResolver
{
    public function resolve(array $claims): ?User
    {
        $username = $this->usernameFromClaims($claims);
        if (empty($username)) {
            Log::warning('[OIDC] Token carries no usable username claim');

            return null;
        }

        $user = User::where('username', '=', $username)
            ->whereNull('deleted_at')
            ->where('activated', '=', '1')
            ->first();

        if ($user) {
            return $user;
        }

        if (config('oidc.provision')) {
            return $this->provision($claims, $username);
        }

        Log::warning('[OIDC] No active Snipe-IT user for token', ['username' => $username]);

        return null;
    }

    protected function usernameFromClaims(array $claims): ?string
    {
        // Match ONLY the admin-configured claim. Falling back to other claims
        // (upn/email) would let a token that lacks the trusted claim authenticate
        // via a different, possibly less-trustworthy or differently-scoped claim
        // -- an account-confusion / bypass vector. Provider differences belong in
        // config (OIDC_API_USERNAME_CLAIM), not a silent fallback. Absent the
        // configured claim, reject.
        $claimName = config('oidc.username_claim', 'preferred_username');

        return ! empty($claims[$claimName]) ? (string) $claims[$claimName] : null;
    }

    protected function provision(array $claims, string $username): ?User
    {
        $user = new User;
        $user->username = $username;
        $user->email = $claims['email'] ?? '';
        $user->first_name = $claims['given_name'] ?? $username;
        $user->last_name = $claims['family_name'] ?? '';
        $user->activated = true;
        // Random unusable password -- these users authenticate only via OIDC.
        $user->password = bcrypt(Str::random(40));
        $user->save();

        Log::info('[OIDC] Just-in-time provisioned Snipe-IT user', ['username' => $username]);

        return $user;
    }
}
