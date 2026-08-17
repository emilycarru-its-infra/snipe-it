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

    public function test_exhibit_board_moved_to_deployments()
    {
        // The exhibit board lives at /deployments/exhibits now; the old
        // reports URL forwards, filters intact.
        $this->actingAs($this->superuser())
            ->get('/reports/exhibit?year=2026')
            ->assertRedirect('/deployments/exhibits?year=2026');

        $this->actingAs($this->superuser())
            ->get(route('deployments.exhibits'))
            ->assertOk();
    }

    public function test_contracts_report_redirects_to_the_merged_page()
    {
        // The contracts dashboard was folded into /contracts; the old URL is
        // kept alive as a redirect for bookmarks and emailed links.
        $this->actingAs($this->superuser())
            ->get(route('reports.contracts'))
            ->assertRedirect(route('contracts.index'));
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
