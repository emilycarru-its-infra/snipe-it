<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * Most writes reach notify() through AssetObserver. Writes that skip model
 * events — audits, query-builder cascades, quiet saves — announce themselves
 * through announce() and announceMany(), so the listener hears about them in
 * seconds rather than at its next reconciliation pass.
 */
class AssetChangeNotifier
{
    /**
     * Columns whose change on its own says nothing about the asset. A save
     * that touched only these is not announced.
     */
    private const IGNORED_COLUMNS = ['updated_at'];

    /** The most ids one announcement lists; asset_count carries the total. */
    private const MAX_LISTED_IDS = 500;

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

        return $this->send([
            'event' => $event,
            'asset_id' => $asset->id,
            'asset_tag' => $asset->asset_tag,
            'serial' => $asset->serial,
            'changed' => $changed,
        ], 'asset '.$asset->id.' ('.$event.')');
    }

    /**
     * One request for a set of assets written together below the model
     * layer. The listener rebuilds from the API, so the ids are only for its
     * log; the list is capped so a cascade over thousands stays small.
     */
    public function notifyMany(iterable $assetIds, string $reason, array $changedColumns = []): bool
    {
        if (empty(config('ecu.asset_change_webhook.url'))) {
            return false;
        }

        $ids = collect($assetIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return false;
        }

        return $this->send([
            'event' => 'updated',
            'reason' => $reason,
            'asset_id' => $ids->count() === 1 ? $ids->first() : null,
            'asset_ids' => $ids->take(self::MAX_LISTED_IDS)->all(),
            'asset_count' => $ids->count(),
            'changed' => array_values(array_diff($changedColumns, self::IGNORED_COLUMNS)),
        ], $ids->count().' asset(s) ('.$reason.')');
    }

    /**
     * Announce one asset after the request finishes, for a save made without
     * model events.
     */
    public static function announce(Asset $asset, string $event, array $changedColumns = []): void
    {
        defer(function () use ($asset, $event, $changedColumns) {
            app(self::class)->notify($asset, $event, $changedColumns);
        });
    }

    /**
     * Announce a set of assets after the request finishes. Does nothing for
     * an empty set.
     */
    public static function announceMany(iterable $assetIds, string $reason, array $changedColumns = []): void
    {
        $ids = collect($assetIds)->all();
        if ($ids === []) {
            return;
        }

        defer(function () use ($ids, $reason, $changedColumns) {
            app(self::class)->notifyMany($ids, $reason, $changedColumns);
        });
    }

    /**
     * The ids in $query that an update to $values would actually change.
     * Call it before the update: a cascade that re-writes the value an asset
     * already holds is not a change worth announcing.
     */
    public static function idsChangedBy(Builder $query, array $values): array
    {
        return (clone $query)
            ->where(function ($any) use ($values) {
                foreach ($values as $column => $value) {
                    $any->orWhere(function ($differs) use ($column, $value) {
                        if ($value === null || $value === '') {
                            $differs->whereNotNull($column);
                        } else {
                            $differs->whereNull($column)->orWhere($column, '<>', $value);
                        }
                    });
                }
            })
            ->pluck((new Asset)->qualifyColumn('id'))
            ->all();
    }

    private function send(array $payload, string $label): bool
    {
        $config = config('ecu.asset_change_webhook');

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
                ->post($config['url'], $payload)
                ->throw();

            return true;
        } catch (\Throwable $e) {
            Log::warning('Asset change webhook failed for '.$label.': '.$e->getMessage());

            return false;
        }
    }
}
