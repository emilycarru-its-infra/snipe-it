<?php

namespace App\Policies;

/**
 * The vendor price catalog is procurement reference data, so it rides the
 * same `orders` permission set as the requisitions built from it.
 */
class CatalogItemPolicy extends SnipePermissionsPolicy
{
    protected function columnName()
    {
        return 'orders';
    }
}
