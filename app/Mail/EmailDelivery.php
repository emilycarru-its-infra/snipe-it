<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use App\Services\Teams\TeamsChannels;

/**
 * Where one of the app's notifications is delivered: email, a Teams card, or
 * both.
 *
 * Every notification in EmailRegistry declares an audience. The ones addressed
 * to internal staff default to a Teams card, because that is the inbox volume
 * this removes; the ones addressed to the person an item belongs to, or to a
 * vendor or a lessor, stay on email — they are outside the university's Teams
 * and a card would reach nobody.
 *
 * An admin can override the default per email in Settings → Emails, so
 * changing your mind about any one of them is a setting, not a deploy.
 *
 * Every method is defensive in the same way EmailTemplate::recipientsFor() is:
 * a lookup that throws falls back to the registry default rather than dropping
 * the notification. A broken row must not silently stop a notification going
 * out.
 */
class EmailDelivery
{
    public const EMAIL = 'email';

    public const TEAMS = 'teams';

    public const BOTH = 'both';

    /** Audiences whose notifications may be routed to Teams. */
    public const INTERNAL_AUDIENCES = ['admin', 'mixed'];

    /** @return array<string, string> value => display label */
    public static function options(): array
    {
        return [
            self::EMAIL => 'Email only',
            self::TEAMS => 'Teams card only',
            self::BOTH => 'Email and Teams card',
        ];
    }

    /**
     * Whether this email still goes out as email. For a "mixed" audience this
     * governs only the internal copy — the user's own mail is never routed to
     * a channel they cannot read.
     */
    public static function shouldEmail(string $key): bool
    {
        return in_array(self::for($key), [self::EMAIL, self::BOTH], true);
    }

    /** Whether this email also (or instead) posts a card to Teams. */
    public static function shouldPostToTeams(string $key): bool
    {
        if (! in_array(self::for($key), [self::TEAMS, self::BOTH], true)) {
            return false;
        }

        return TeamsChannels::url(self::channelFor($key)) !== null;
    }

    /** The resolved delivery for a key: the admin's override, else the registry default. */
    public static function for(string $key): string
    {
        $entry = EmailRegistry::find($key);

        if (! $entry || ! self::isRoutable($entry)) {
            return self::EMAIL;
        }

        $default = $entry['default_delivery'] ?? self::EMAIL;

        try {
            $override = EmailTemplate::forKey($key)?->delivery;
        } catch (\Throwable $e) {
            return $default;
        }

        return array_key_exists((string) $override, self::options()) ? (string) $override : $default;
    }

    /** The Teams channel key this email posts to. */
    public static function channelFor(string $key): string
    {
        $entry = EmailRegistry::find($key);
        $default = $entry['default_channel'] ?? TeamsChannels::DEFAULT;

        try {
            $override = EmailTemplate::forKey($key)?->teams_channel;
        } catch (\Throwable $e) {
            return $default;
        }

        return TeamsChannels::isKnown($override) ? (string) $override : $default;
    }

    /**
     * Whether an email is eligible for Teams delivery at all. User-facing and
     * external mail is not: the Settings → Emails hub does not offer the
     * selector for it, and a forged form post cannot set one either.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function isRoutable(array $entry): bool
    {
        return in_array($entry['audience'] ?? 'user', self::INTERNAL_AUDIENCES, true)
            && isset($entry['teams']);
    }
}
