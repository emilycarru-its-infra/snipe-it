<?php

namespace App\Services\Teams;

use App\Services\Settings\Preferences;

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
     * The channels this app offers, in the order the settings dropdown shows
     * them: the ones its own notifications plausibly belong in first, then the
     * rest of the estate's channels so a notification can be split out without
     * a code change.
     *
     * These are names Relay resolves in its own team, held in the
     * teams.channels preference (this list is its default). Adding one does not
     * create anything — the channel has to exist and have Relay in it — but a
     * name Relay cannot resolve falls back to Automations with a notice rather
     * than disappearing, so an optimistic list is safe.
     *
     * @return array<string, string> channel name => display label
     */
    public static function keys(): array
    {
        return Preferences::get('teams.channels');
    }

    /**
     * The built-in channel list: the teams.channels default.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'Inventory' => 'Inventory — checkouts, check-ins, audits, requests',
            'Procurement' => 'Procurement — store and vendor orders',
            'Automations' => 'Automations — scheduled reports',
            'Devices' => 'Devices',
            'Macintosh' => 'Macintosh',
            'Windows' => 'Windows',
            'PaperCut' => 'PaperCut',
            'Planning' => 'Planning',
            'ReportMate' => 'ReportMate',
            'Vantage' => 'Vantage',
            'Intune' => 'Intune',
            'Enrollment' => 'Enrollment',
            'Entra' => 'Entra',
            'Adobe' => 'Adobe',
            'Amazon' => 'Amazon',
            'TouchNet' => 'TouchNet',
            'Handbook' => 'Handbook',
            'Patching' => 'Patching',
        ];
    }

    public static function isKnown(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::keys());
    }

    /**
     * The channel used by anything that has not chosen one: the
     * teams.default_channel preference when it is a known channel, else the
     * first channel offered.
     */
    public static function default(): string
    {
        $configured = trim((string) Preferences::get('teams.default_channel'));
        if (self::isKnown($configured)) {
            return $configured;
        }

        return (string) (array_key_first(self::keys()) ?? 'Inventory');
    }

    /** Normalise a stored value to a channel this app actually offers. */
    public static function resolve(?string $key): string
    {
        return self::isKnown($key) ? (string) $key : self::default();
    }
}
