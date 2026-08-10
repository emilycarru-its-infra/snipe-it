<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Tests\TestCase;

/**
 * The three operational dashboards — Deployments, Procurement, Contracts —
 * open for an ITS group member.
 *
 * Every ITS group is configured with the blanket `admin` permission rather
 * than the individual module keys, and SnipePermissionsPolicy honours it, so
 * anything gated by a policy has always been reachable. Anything gated by a
 * bare ability was not, unless someone remembered to define it — which is how
 * /contracts came to render its register but not its charts.
 */
class DashboardAccessTest extends TestCase
{
    private function itsGroupMember(): User
    {
        return User::factory()->create([
            'permissions' => json_encode(['admin' => '1']),
        ]);
    }

    /**
     * @dataProvider dashboards
     */
    public function test_dashboard_opens_for_an_its_group_member(string $route)
    {
        $this->actingAs($this->itsGroupMember())
            ->get(route($route))
            ->assertOk();
    }

    public static function dashboards(): array
    {
        return [
            'deployments' => ['reports.deployments'],
            'procurement' => ['procurement.index'],
            'contracts'   => ['contracts.index'],
        ];
    }

    /**
     * @dataProvider dashboards
     */
    public function test_dashboard_stays_shut_without_permissions(string $route)
    {
        $stranger = User::factory()->create(['permissions' => json_encode([])]);

        $this->actingAs($stranger)
            ->get(route($route))
            ->assertForbidden();
    }
}
