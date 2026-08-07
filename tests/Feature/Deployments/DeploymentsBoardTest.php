<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentWave;
use App\Models\Statuslabel;
use App\Models\User;
use Tests\TestCase;

class DeploymentsBoardTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_board_renders_the_stage_rail_from_the_stage_catalog()
    {
        $wave = DeploymentWave::create(['name' => 'Rail Wave', 'fiscal_year' => 'FY2026-27']);
        DeploymentItem::create(['wave_id' => $wave->id]);

        $response = $this->actingAs($this->superuser())
            ->get(route('reports.deployments', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.rail_title'));

        // One chevron per catalog stage, seeded Planned → Deployed.
        $content = $response->getContent();
        foreach (['Planned', 'Ordered', 'Arrived', 'Inventoried', 'Provisioned', 'Deployed'] as $stage) {
            $this->assertStringContainsString($stage, $content);
        }
        $this->assertStringContainsString('dp-chev', $content);
    }

    public function test_decommission_lane_shows_collecting_devices_and_holding_locations()
    {
        $processing = Statuslabel::factory()->pending()->create(['name' => 'Processing (Return)']);
        $asset = Asset::factory()->create([
            'asset_tag' => 'DECOM-1',
            'status_id' => $processing->id,
        ]);

        $archived = Statuslabel::factory()->archived()->create(['name' => 'Archived (Returned)']);
        Asset::factory()->create([
            'asset_tag' => 'DECOM-DONE',
            'status_id' => $archived->id,
            'decommission_date' => now()->format('Y-m-d'),
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.deployments'))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.decom_title'))
            ->assertSee('DECOM-1')
            ->assertSee('Processing (Return)')
            ->assertSee(trans('admin/deployments/general.decom_locations'));
    }

    public function test_decommissioned_devices_leave_collecting_and_count_as_archived()
    {
        $processing = Statuslabel::factory()->pending()->create(['name' => 'Processing (Donation)']);
        Asset::factory()->create([
            'asset_tag' => 'DECOM-GONE',
            'status_id' => $processing->id,
            'decommission_date' => now()->format('Y-m-d'),
        ]);

        // A stamped decommission date means the device left our management —
        // it must not linger on the collecting table.
        $content = $this->actingAs($this->superuser())
            ->get(route('reports.deployments'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('DECOM-GONE', $content);
    }

    public function test_deployments_sits_second_on_the_reports_hub_and_in_the_top_toolbar()
    {
        $content = $this->actingAs($this->superuser())
            ->get(route('reports.index'))
            ->assertOk()
            ->getContent();

        // Hub cards: Procurement, then Deployments, then Contracts. The help
        // strings are unique to the cards (the tile titles also appear in
        // the top toolbar, in a different order).
        $procurement = strpos($content, trans('admin/reports/general.hub_tile_procurement_help'));
        $deployments = strpos($content, trans('admin/reports/general.hub_tile_deployments_help'));
        $contracts = strpos($content, trans('admin/reports/general.hub_tile_contracts_help'));
        $this->assertNotFalse($procurement);
        $this->assertNotFalse($deployments);
        $this->assertNotFalse($contracts);
        $this->assertTrue($procurement < $deployments && $deployments < $contracts);

        // Top toolbar: the entry sits between Assets and Procurement. Match
        // the li markup (class="...") — the bare names also appear earlier
        // in the responsive drop-out CSS.
        $assetsNav = strpos($content, 'class="topnav-assets');
        $deployNav = strpos($content, 'class="topnav-deployments');
        $procNav = strpos($content, 'class="topnav-procurement');
        $this->assertNotFalse($deployNav);
        $this->assertTrue($assetsNav < $deployNav && $deployNav < $procNav);
    }
}
