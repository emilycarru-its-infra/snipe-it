<?php

namespace App\Policies;

class LicenseModelPolicy extends SnipePermissionsPolicy
{
    protected function columnName()
    {
        return 'licensemodels';
    }
}
