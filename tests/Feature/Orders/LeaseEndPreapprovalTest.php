<?php

namespace Tests\Feature\Orders;

use App\Models\Asset;
use App\Models\User;
use Tests\TestCase;

/**
 * The lease-end pre-approval envelope: every schedule ending in a fiscal
 * year carries its original value forward as that year's replacement
 * estimate, reported beside the budget rather than inside it. Both
 * regressions here came from the F2 native-lease migration — lease_end_date
 * became a real DATE column, so the old varchar guards (!= '') matched
 * nothing and Carbon/date-string parsing drifted.
 */
class LeaseEndPreapprovalTest extends TestCase
{
    public function test_ending_leases_appear_in_the_pipeline_preapproval()
    {
        $this->actingAs(User::factory()->superuser()->create());

        Asset::factory()->create([
            'lease_contract_id' => 'ECI20220201',
            'lease_end_date' => '2026-04-01',
            'ownership_type' => 'Lease to Return',
            'purchase_cost' => 36969.34,
        ]);

        $response = $this->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']));

        $response->assertOk()->assertSee('36,969.34');
        $this->assertStringNotContainsString(
            '$0.00 lease-end pre-approval',
            $response->getContent()
        );
    }
}
