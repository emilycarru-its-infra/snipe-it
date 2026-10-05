<?php

namespace Tests\Support;

use App\Models\Supplier;

/**
 * Two lessors with declared contract prefixes, for tests whose lease
 * fixtures need a lessor to own them. Lessor One is the CSI lessor.
 */
trait DeclaresLessors
{
    protected function declareLessors(): void
    {
        Supplier::factory()->create(['name' => 'Lessor One', 'contract_prefixes' => '700100-']);
        Supplier::factory()->create(['name' => 'Lessor Two', 'contract_prefixes' => '700200-,QQ']);
        config(['leasing.csi_lessor' => 'Lessor One']);
    }
}
