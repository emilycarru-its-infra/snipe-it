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
        $gone = Asset::factory()->create([
            'asset_tag' => 'DECOM-GONE',
            'status_id' => $processing->id,
            'decommission_date' => now()->format('Y-m-d'),
        ]);
        // Strip the factory-computed EOL via the query builder (the factory's
        // afterMaking overwrites any override, and the observer recomputes on
        // model saves) so the device cannot also surface on the lease-end/EOL
        // look-ahead list — this test is about the lane.
        Asset::query()->whereKey($gone->id)->update(['purchase_date' => null, 'asset_eol_date' => null]);

        // A stamped decommission date means the device left our management —
        // it must not linger on the collecting table.
        $content = $this->actingAs($this->superuser())
            ->get(route('reports.deployments'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('DECOM-GONE', $content);
    }

    public function test_fy_selector_is_a_bounded_window_not_every_stray_device_date()
    {
        // A single far-future EOL date used to put its FY in the picker.
        // Set via the query builder — the factory recomputes EOL from the
        // purchase date, silently discarding a create() override.
        $stray = Asset::factory()->create();
        Asset::query()->whereKey($stray->id)->update(['asset_eol_date' => '2036-06-30']);

        $content = $this->actingAs($this->superuser())
            ->get(route('reports.deployments'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('FY2036-37', $content);

        // The window still reaches next year for early planning.
        $startYear = now()->month >= 4 ? now()->year : now()->year - 1;
        $nextFy = sprintf('FY%d-%02d', $startYear + 1, ($startYear + 2) % 100);
        $this->assertStringContainsString($nextFy, $content);
    }

    public function test_future_fy_shows_the_lease_end_lookahead_list_for_planning()
    {
        $startYear = (now()->month >= 4 ? now()->year : now()->year - 1) + 1;
        $nextFy = sprintf('FY%d-%02d', $startYear, ($startYear + 1) % 100);

        // A device whose lease ends in that FY, not yet on any wave. The
        // lease end goes in via the query builder so the factory's computed
        // EOL (which lands in a different FY) can't muddy the reason.
        $planned = Asset::factory()->create(['asset_tag' => 'PLAN-2728']);
        Asset::query()->whereKey($planned->id)->update([
            'lease_end_date' => sprintf('%d-06-30', $startYear),
            'asset_eol_date' => null,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.deployments', ['fiscal_year' => $nextFy]))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.forecast_summary', ['count' => 1, 'fy' => $nextFy]))
            ->assertSee('PLAN-2728');
    }

    public function test_legacy_fleet_callout_surfaces_unfunded_aging_devices()
    {
        $legacy = Statuslabel::factory()->rtd()->create(['name' => 'Active (Legacy)']);
        Asset::factory()->create([
            'asset_tag' => 'LEGACY-1',
            'status_id' => $legacy->id,
            'purchase_date' => '2016-08-01',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.deployments'))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.legacy_title', ['count' => 1]))
            ->assertSee(trans('admin/deployments/general.legacy_view_devices'));
    }

    public function test_deployments_sits_second_on_the_reports_hub_and_in_the_top_toolbar()
    {
        $content = $this->actingAs($this->superuser())
            ->get(route('reports.index'))
            ->assertOk()
            ->getContent();

        // Hub cards: Procurement, then Deployments. The help strings are
        // unique to the cards (the tile titles also appear in the top
        // toolbar, in a different order). Contracts used to follow
        // Deployments here; its dashboard was folded into /contracts, so it
        // no longer has a card on this hub at all.
        $procurement = strpos($content, trans('admin/reports/general.hub_tile_procurement_help'));
        $deployments = strpos($content, trans('admin/reports/general.hub_tile_deployments_help'));
        $this->assertNotFalse($procurement);
        $this->assertNotFalse($deployments);
        $this->assertTrue($procurement < $deployments);
        $this->assertStringNotContainsString(route('reports.contracts', [], false), $content);

        // Top toolbar: the entry sits between Assets and Procurement. Match
        // the li markup (class="...") — the bare names also appear earlier
        // in the responsive drop-out CSS.
        $assetsNav = strpos($content, 'class="topnav-assets');
        $deployNav = strpos($content, 'class="topnav-deployments');
        $procNav = strpos($content, 'class="topnav-procurement');
        $this->assertNotFalse($deployNav);
        $this->assertTrue($assetsNav < $deployNav && $deployNav < $procNav);
    }

    public function test_sidebar_reports_entries_are_one_word_each()
    {
        $content = $this->actingAs($this->superuser())
            ->get(route('reports.index'))
            ->assertOk()
            ->getContent();

        // Deployments took Lessor Breakdown's treeview slot; the multi-word
        // dashboard names are gone from the treeview.
        $this->assertStringNotContainsString('>'.trans('admin/purchase-orders/general.report_lessor_breakdown').'<', preg_replace('/\s+/', '', $content));
        foreach ([
            trans('general.procurement'),
            trans('admin/deployments/general.dashboard_title'),
            trans('admin/contracts/general.contracts'),
            trans('admin/reports/general.hub_tile_transactions'),
            trans('admin/reports/general.nav_printers'),
            trans('admin/reports/general.hub_tile_exhibit'),
        ] as $label) {
            $this->assertStringContainsString($label, $content);
        }
        $this->assertSame('Printers', trans('admin/reports/general.nav_printers'));
    }
}
