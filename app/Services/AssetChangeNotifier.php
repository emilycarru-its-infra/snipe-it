<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells the Inventory automations function app that an asset changed.
 *
 * The receiver (snipe-asset-changed) never trusts the body for data — it
 * rebuilds assets.csv from the API — so the payload is only what makes the
 * log readable: which asset, which event, which columns. A failure here is
 * logged and swallowed: the 15-minute reconciliation pass on the function
 * side catches anything a lost webhook missed, and an asset save must never
 * fail because a downstream listener was unreachable.
 */
class AssetChangeNotifier
{
    /**
     * Columns whose change on its own says nothing about the asset. A save
     * that touched only these is not announced.
     */
    private const IGNORED_COLUMNS = ['updated_at'];

    public function notify(Asset $asset, string $event, array $changedColumns = []): bool
    {
        $config = config('ecu.asset_change_webhook');
        if (empty($config['url'])) {
            return false;
        }

        $changed = array_values(array_diff($changedColumns, self::IGNORED_COLUMNS));
        if ($event === 'updated' && $changedColumns !== [] && $changed === []) {
            return false;
        }

        $headers = ['Accept' => 'application/json'];
        if (! empty($config['key'])) {
            $headers['x-functions-key'] = $config['key'];
        }
        if (! empty($config['secret'])) {
            $headers['X-Asset-Change-Secret'] = $config['secret'];
        }

        try {
            Http::withHeaders($headers)
                ->timeout($config['timeout'] ?? 5)
                ->post($config['url'], [
                    'event' => $event,
                    'asset_id' => $asset->id,
                    'asset_tag' => $asset->asset_tag,
                    'serial' => $asset->serial,
                    'changed' => $changed,
                ])
                ->throw();

            return true;
        } catch (\Throwable $e) {
            Log::warning('Asset change webhook failed for asset '.$asset->id.' ('.$event.'): '.$e->getMessage());

            return false;
        }
    }
}
