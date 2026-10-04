<?php

namespace App\Http\Transformers;

use App\Models\Setting;
use App\Services\Settings\SettingsPages;

/**
 * One Admin → Settings page over the API. The column allow-list and the
 * write-only handling of credentials live in SettingsPages, which the PATCH
 * side shares, so a key can never be readable here and unknown there.
 */
class SettingsTransformer
{
    /** @return array{page: string, settings: array<string, mixed>} */
    public function transformPage(string $page, Setting $setting): array
    {
        return [
            'page' => $page,
            'settings' => SettingsPages::read($page, $setting),
        ];
    }
}
