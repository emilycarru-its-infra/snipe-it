<?php

namespace App\Policies;

/**
 * Product identities are license catalogue configuration, kept by the same
 * people who keep license models, so they share that permission column.
 */
class ProductIdentityPolicy extends SnipePermissionsPolicy
{
    protected function columnName(): string
    {
        return 'licensemodels';
    }
}
