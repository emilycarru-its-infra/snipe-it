<?php

namespace App\Services\Deployments;

use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentType;
use App\Models\DeploymentWave;
use App\Services\Settings\StoreInput;

/**
 * The two editable deployment catalogs (wave types, per-device stages) for
 * both the admin catalog page and the API: which catalogs exist, which
 * fields each edit may touch, and what removing an entry does.
 */
final class DeploymentCatalogs
{
    /** catalog key => [model class, label key]. */
    public const CATALOGS = [
        'types' => [DeploymentType::class, 'catalog_types'],
        'stages' => [DeploymentStage::class, 'catalog_stages'],
    ];

    /** @var array<string, 'string'|'int'|'bool'|'id'> */
    private const COMMON = [
        'name' => 'string',
        'color' => 'string',
        'sort_order' => 'int',
        'active' => 'bool',
    ];

    /** @var array<string, array<string, 'string'|'int'|'bool'|'id'>> */
    private const EXTRA = [
        'types' => ['moves_devices' => 'bool'],
        'stages' => ['is_terminal' => 'bool', 'is_on_hand' => 'bool', 'maps_to_status_id' => 'id'],
    ];

    /**
     * @return array{0: class-string<DeploymentType|DeploymentStage>, 1: string}
     */
    public static function resolve(string $catalog): array
    {
        abort_unless(isset(self::CATALOGS[$catalog]), 404);

        return self::CATALOGS[$catalog];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function attributes(string $catalog, array $input, bool $partial = false): array
    {
        self::resolve($catalog);

        return StoreInput::pick($input, self::COMMON + self::EXTRA[$catalog], $partial);
    }

    /**
     * Remove an entry. One still in use (a wave of that type, a device at
     * that stage) is deactivated instead, hiding it from pickers without
     * orphaning rows. Returns true when the row was deleted.
     */
    public static function remove(string $catalog, DeploymentType|DeploymentStage $item): bool
    {
        self::resolve($catalog);

        $inUse = $catalog === 'types'
            ? DeploymentWave::where('deployment_type_id', $item->getKey())->exists()
            : DeploymentItem::where('stage_id', $item->getKey())->exists();

        if ($inUse) {
            $item->active = false;
            $item->save();

            return false;
        }

        $item->delete();

        return true;
    }
}
