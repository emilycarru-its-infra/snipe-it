<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Tests\TestCase;

/**
 * Smoke coverage for the tile-dashboard report routes that render live
 * aggregates and had no test of their own. Guards against controller-level
 * regressions (stale model/table names, broken queries) that only surface
 * when the page is actually requested — e.g. the Fleet Health dashboard
 * referencing the pre-rename App\Models\AssetMaintenance / asset_maintenances.
 */
class ReportDashboardsSmokeTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_reports_landing_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.index'))
            ->assertOk();
    }

    public function test_contracts_report_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.contracts'))
            ->assertOk();
    }

    public function test_fleet_health_dashboard_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.fleet-health'))
            ->assertOk();
    }
}
