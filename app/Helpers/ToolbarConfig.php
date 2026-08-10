<?php

namespace App\Helpers;

use App\Models\Setting;

/**
 * GUI-editable toolbar: which top-level tabs show, and in what order.
 *
 * The registry below is the source of truth for what CAN appear; the
 * stored config (settings.toolbar_config JSON) only reorders and hides.
 * Permissions stay in the blade — hiding a tab never grants anything,
 * and an unknown key in the config is ignored, so a bad write can't
 * break the chrome. Edited at /admin/toolbar or over
 * /api/v1/settings/toolbar.
 */
class ToolbarConfig
{
    /** key => [default sort, lang key]. Search, Create New and the gear are fixed chrome. */
    public const TABS = [
        'assets' => [10, 'general.assets'],
        'deployments' => [20, 'admin/deployments/general.dashboard_title'],
        'procurement' => [30, 'general.procurement'],
        'contracts' => [40, 'admin/contracts/general.contracts'],
        'consumables' => [50, 'general.consumables'],
        'users' => [60, 'general.users'],
    ];

    private static ?array $config = null;

    private static function config(): array
    {
        if (self::$config === null) {
            $raw = Setting::getSettings()->toolbar_config ?? null;
            $decoded = $raw ? json_decode((string) $raw, true) : null;
            self::$config = is_array($decoded) ? $decoded : [];
        }

        return self::$config;
    }

    /** Forget the memoized config (after a save, and in tests). */
    public static function flush(): void
    {
        self::$config = null;
    }

    public static function visible(string $key): bool
    {
        foreach (self::config()['tabs'] ?? [] as $tab) {
            if (($tab['key'] ?? null) === $key) {
                return ! ($tab['hidden'] ?? false);
            }
        }

        return true;
    }

    public static function order(string $key): int
    {
        foreach (self::config()['tabs'] ?? [] as $index => $tab) {
            if (($tab['key'] ?? null) === $key) {
                return ($index + 1) * 10;
            }
        }

        return self::TABS[$key][0] ?? 999;
    }

    /** The registry merged with the stored config — what the editor shows. */
    public static function editorRows(): array
    {
        $rows = [];
        foreach (self::config()['tabs'] ?? [] as $tab) {
            $key = $tab['key'] ?? null;
            if ($key && isset(self::TABS[$key])) {
                $rows[$key] = ['key' => $key, 'label' => trans(self::TABS[$key][1]), 'hidden' => (bool) ($tab['hidden'] ?? false)];
            }
        }
        foreach (self::TABS as $key => [$sort, $langKey]) {
            if (! isset($rows[$key])) {
                $rows[$key] = ['key' => $key, 'label' => trans($langKey), 'hidden' => false];
            }
        }

        return array_values($rows);
    }

    /** Validate + persist a tabs array; returns the normalized config. */
    public static function save(array $tabs): array
    {
        $normalized = [];
        foreach ($tabs as $tab) {
            $key = $tab['key'] ?? null;
            if ($key && isset(self::TABS[$key]) && ! isset($normalized[$key])) {
                $normalized[$key] = ['key' => $key, 'hidden' => (bool) ($tab['hidden'] ?? false)];
            }
        }

        $config = ['tabs' => array_values($normalized)];

        $setting = Setting::getSettings();
        $setting->toolbar_config = json_encode($config);
        $setting->save();
        self::flush();

        return $config;
    }
}
