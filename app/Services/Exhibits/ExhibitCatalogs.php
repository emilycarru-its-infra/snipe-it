<?php

namespace App\Services\Exhibits;

use App\Models\Exhibit;
use App\Models\ExhibitProject;
use App\Models\ExhibitProjectType;
use App\Models\ExhibitStatus;
use App\Services\Settings\StoreInput;

/**
 * The three editable exhibit catalogs (exhibits, project types, statuses)
 * for both the exhibit-config pages and the API: which catalogs exist,
 * which fields each edit may touch, and what removing an entry does. One
 * definition, so the two surfaces cannot drift apart.
 */
final class ExhibitCatalogs
{
    /** catalog key => [model class, project FK column, label key]. */
    public const CATALOGS = [
        'exhibits' => [Exhibit::class, 'exhibit_id', 'catalog_exhibits'],
        'project-types' => [ExhibitProjectType::class, 'project_type_id', 'catalog_project_types'],
        'statuses' => [ExhibitStatus::class, 'status_id', 'catalog_statuses'],
    ];

    /** @var array<string, 'string'|'int'|'bool'|'id'> */
    public const FIELDS = [
        'name' => 'string',
        'color' => 'string',
        'sort_order' => 'int',
        'active' => 'bool',
    ];

    /**
     * @return array{0: class-string<Exhibit|ExhibitProjectType|ExhibitStatus>, 1: string, 2: string}
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
    public static function attributes(array $input, bool $partial = false): array
    {
        return StoreInput::pick($input, self::FIELDS, $partial);
    }

    /**
     * Remove an entry. One that projects still point at is deactivated
     * instead (hidden from pickers and widgets), so no project row is
     * orphaned. Returns true when the row was deleted.
     */
    public static function remove(string $catalog, Exhibit|ExhibitProjectType|ExhibitStatus $item): bool
    {
        [, $fk] = self::resolve($catalog);

        if (ExhibitProject::where($fk, $item->getKey())->exists()) {
            $item->active = false;
            $item->save();

            return false;
        }

        $item->delete();

        return true;
    }
}
