<?php

namespace App\Services\Teams;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a channel key ("devices", "procurement", …) to the Power Automate
 * webhook URL that posts to it.
 *
 * The URLs are app settings resolved from Key Vault at boot, not rows in the
 * settings table: they are secrets, they belong to the deployment, and the
 * estate already keeps them as workflow-url-<channel> in the
 * commits-teams-webhooks vault. What the database stores — per email, in the
 * Settings → Emails hub — is only which key an email posts to.
 *
 * The "default" key falls back to the single webhook_endpoint the settings UI
 * has always written, so an install that has configured nothing new keeps
 * posting exactly where it did before.
 */
class TeamsChannels
{
    public const DEFAULT = 'default';

    /**
     * Channel keys the app knows about, in the order the settings dropdown
     * shows them. A key with no URL configured is still offered — an
     * unconfigured channel is a deployment gap, not an invalid choice, and
     * TeamsNotifier logs it rather than failing a checkout.
     *
     * @return array<string, string> key => display label
     */
    public static function keys(): array
    {
        return [
            self::DEFAULT => 'Default (Settings → Slack endpoint)',
            'devices' => 'Devices — checkouts, check-ins, audits',
            'procurement' => 'Procurement — store and vendor orders',
            'reports' => 'Reports — scheduled digests',
            'requests' => 'Requests — asset requests',
        ];
    }

    public static function isKnown(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::keys());
    }

    /**
     * The webhook URL for a channel key, or null when that channel has no URL
     * configured. Falls back to the "default" channel — an email routed to a
     * channel the deployment has not wired up yet still lands somewhere a
     * human reads, rather than disappearing.
     */
    public static function url(?string $key): ?string
    {
        $key = self::isKnown($key) ? $key : self::DEFAULT;

        $url = self::configured($key);

        if ($url === null && $key !== self::DEFAULT) {
            Log::info('Teams channel "'.$key.'" has no webhook URL configured; falling back to the default channel.');
            $url = self::configured(self::DEFAULT);
        }

        return $url;
    }

    /** Which of the known keys actually have a URL behind them. */
    public static function configuredKeys(): array
    {
        return array_values(array_filter(
            array_keys(self::keys()),
            fn ($key) => self::configured($key) !== null
        ));
    }

    private static function configured(string $key): ?string
    {
        $url = trim((string) (config('ecu.teams.channels.'.$key) ?? ''));

        if ($url === '' && $key === self::DEFAULT) {
            $url = self::settingsEndpoint();
        }

        // A Key Vault reference that never resolved arrives verbatim as
        // "@Microsoft.KeyVault(...)", and an undefined pipeline macro as
        // "$(Something)". Posting to either is a silent no-delivery, so treat
        // them as unconfigured and say so.
        if ($url !== '' && (str_starts_with($url, '@Microsoft.KeyVault') || str_starts_with($url, '$('))) {
            Log::warning('Teams channel "'.$key.'" holds an unresolved config reference; treating it as unconfigured.');

            return null;
        }

        return $url === '' ? null : $url;
    }

    /**
     * The endpoint the Settings → Slack form writes. Only used for the default
     * channel, and only when it is a Workflows URL — the retired Office 365
     * connector format takes a different payload entirely, and this card
     * builder only speaks Adaptive Cards.
     */
    private static function settingsEndpoint(): string
    {
        try {
            $settings = Setting::getSettings();
        } catch (\Throwable $e) {
            return '';
        }

        if (! $settings || $settings->webhook_selected !== 'microsoft') {
            return '';
        }

        $endpoint = trim((string) $settings->webhook_endpoint);

        return str_contains($endpoint, 'workflows') ? $endpoint : '';
    }
}
