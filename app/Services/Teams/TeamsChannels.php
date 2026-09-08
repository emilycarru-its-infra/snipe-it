<?php

namespace App\Services\Teams;

/**
 * The Teams channels this app can post to.
 *
 * These are channel *names*, not endpoints. Relay — the estate's Teams bot,
 * fronted by commits-functions /api/post-card — resolves a name to a channel
 * in its own team, so adding Relay to a channel in Teams is the entire
 * onboarding: no webhook, no flow, no secret, and nothing to configure here.
 *
 * A name Relay cannot resolve is not lost: it falls back to the Automations
 * channel with a notice naming what was unrouted, which is louder than a
 * silent drop and easier to act on.
 */
class TeamsChannels
{
    /**
     * Channel keys the app offers, in the order the settings dropdown shows
     * them. The key is the channel name Relay is given.
     *
     * @return array<string, string> channel name => display label
     */
    public static function keys(): array
    {
        return [
            'Inventory' => 'Inventory — checkouts, check-ins, audits, requests',
            'Procurement' => 'Procurement — store and vendor orders',
            'Automations' => 'Automations — scheduled reports',
        ];
    }

    public static function isKnown(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::keys());
    }

    /** The channel used by anything that has not chosen one. */
    public static function default(): string
    {
        $configured = trim((string) config('ecu.teams.default_channel'));

        return self::isKnown($configured) ? $configured : 'Inventory';
    }

    /** Normalise a stored value to a channel this app actually offers. */
    public static function resolve(?string $key): string
    {
        return self::isKnown($key) ? (string) $key : self::default();
    }
}
