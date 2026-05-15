<?php

namespace App\Policies;

class OrderPolicy extends SnipePermissionsPolicy
{
    protected function columnName()
    {
        return 'orders';
    }
}
