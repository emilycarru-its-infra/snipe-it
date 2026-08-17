<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\LeaseDecision;
use App\Models\User;
use App\Services\Deployments\RefreshForecast;
use Tests\TestCase;

/**
 * Pushing a refresh to the next fiscal year from the planning page: an
 * extend decision stamped with the target FY moves the device between
 * planning lists — out of the year its dates put it in, into the year
 * the planner chose.
 */
class PlanningDeferTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function dueAsset(string $tag, string $eol): Asset
    {
        $asset = Asset::factory()->create(['asset_tag' => $tag]);
        Asset::query()->whereKey($asset->id)->update([
            'asset_eol_date' => $eol,
            'lease_end_date' => null,
        ]);

        return $asset->fresh();
    }

    public function test_defer_moves_a_device_to_the_next_fiscal_year()
    {
        $asset = $this->dueAsset('DEFER-1', '2026-10-01');

        $this->actingAs($this->superuser())
            ->post(route('deployments.planning.defer'), [
                'asset_ids' => [$asset->id],
                'fiscal_year' => 'FY2026-27',
            ])
            ->assertRedirect(route('deployments.planning', ['fiscal_year' => 'FY2026-27']));

        $decision = LeaseDecision::where('asset_id', $asset->id)->first();
        $this->assertEquals('extend', $decision->decision_type);
        $this->assertEquals('FY2027-28', $decision->deferred_to_fy);

        $forecast = new RefreshForecast;
        $this->assertFalse($forecast->forFiscalYear('FY2026-27')->contains('id', $asset->id));

        $moved = $forecast->forFiscalYear('FY2027-28')->firstWhere('id', $asset->id);
        $this->assertNotNull($moved);
        $this->assertEquals('deferred', $moved->refresh_reason);
    }

    public function test_cancelling_the_decision_restores_the_original_year()
    {
        $asset = $this->dueAsset('DEFER-2', '2026-10-01');

        $this->actingAs($this->superuser())
            ->post(route('deployments.planning.defer'), [
                'asset_ids' => [$asset->id],
                'fiscal_year' => 'FY2026-27',
            ]);

        LeaseDecision::where('asset_id', $asset->id)->update(['status' => 'cancelled']);

        $forecast = new RefreshForecast;
        $this->assertTrue($forecast->forFiscalYear('FY2026-27')->contains('id', $asset->id));
        $this->assertFalse($forecast->forFiscalYear('FY2027-28')->contains('id', $asset->id));
    }

    public function test_old_forecast_url_redirects_to_planning()
    {
        $this->actingAs($this->superuser())
            ->get('/deployments/forecast?fiscal_year=FY2026-27')
            ->assertRedirect('/deployments/planning?fiscal_year=FY2026-27');
    }
}
