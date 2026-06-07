<?php

namespace Tests\Feature\Reports;

use App\Models\Asset;
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

    public function test_landing_promotes_exhibit_tile_and_demotes_fleet_health()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.index'))
            ->assertOk()
            // Exhibit is a top-row dashboard tile; Fleet Health sits below it
            // in the standard-reports row.
            ->assertSeeInOrder([
                route('reports.exhibit', [], false),
                route('reports.fleet-health', [], false),
            ], false);
    }

    public function test_contracts_report_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.contracts'))
            ->assertOk();
    }

    public function test_fleet_health_dashboard_renders()
    {
        // Seed an asset with a purchase_date so the age-histogram path runs —
        // it computes asset age via Carbon and would 500 on a removed method
        // (floatDiffInYears) that the empty-data render never reaches.
        Asset::factory()->create(['purchase_date' => now()->subYears(3)->toDateString()]);

        $this->actingAs($this->superuser())
            ->get(route('reports.fleet-health'))
            ->assertOk();
    }
}
