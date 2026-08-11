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
            ->assertSee(trans('admin/deployments/general.flow_backlog_note', ['count' => 1]))
            ->assertSee('PLAN-2728');
    }

    public function test_procurement_order_lines_surface_on_the_flow_as_ordered_and_arrived()
    {
        $startYear = now()->month >= 4 ? now()->year : now()->year - 1;
        $currentFy = sprintf('FY%d-%02d', $startYear, ($startYear + 1) % 100);

        $order = \App\Models\Order::factory()->create([
            'status' => 'ordered',
            'is_planned' => false,
            'fiscal_year' => $currentFy,
            'order_number' => 'PVTEST99',
        ]);
        \App\Models\OrderItem::factory()->create([
            'order_id' => $order->id,
            'description' => 'Latitude 5560 Refresh Line',
            'quantity' => 3,
        ]);

        $content = $this->actingAs($this->superuser())
            ->get(route('reports.deployments'))
            ->assertOk()
            ->getContent();

        // The money side and the physical side are the same devices: the
        // order line shows on the flow at Ordered, tagged with its order.
        $this->assertStringContainsString('PVTEST99', $content);
        $this->assertStringContainsString('Latitude 5560 Refresh Line', $content);
        $this->assertStringContainsString(e('Latitude 5560 Refresh Line ×3'), $content);
    }

    public function test_bulk_group_labels_a_cohort()
    {
        $wave = DeploymentWave::create(['name' => 'Cohort Wave', 'fiscal_year' => 'FY2026-27']);
        $a = DeploymentItem::create(['wave_id' => $wave->id]);
        $b = DeploymentItem::create(['wave_id' => $wave->id]);

        $this->actingAs($this->superuser())
            ->post(route('deployment-items.bulk-group'), [
                'item_ids' => [$a->id, $b->id],
                'group_label' => 'Room D2416',
            ])
            ->assertSessionHas('success');

        $this->assertSame('Room D2416', $a->fresh()->group_label);
        $this->assertSame('Room D2416', $b->fresh()->group_label);
    }

    public function test_future_fy_has_no_decommissioning_section()
    {
        $processing = Statuslabel::factory()->pending()->create(['name' => 'Processing (Return)']);
        Asset::factory()->create(['asset_tag' => 'FUTURE-NOPE', 'status_id' => $processing->id]);

        $startYear = (now()->month >= 4 ? now()->year : now()->year - 1) + 1;
        $nextFy = sprintf('FY%d-%02d', $startYear, ($startYear + 1) % 100);

        $this->actingAs($this->superuser())
            ->get(route('reports.deployments', ['fiscal_year' => $nextFy]))
            ->assertOk()
            ->assertDontSee(trans('admin/deployments/general.decom_title'));
    }

    public function test_past_fy_groups_outgoing_devices_into_pickups_not_current_processing()
    {
        $processing = Statuslabel::factory()->pending()->create(['name' => 'Processing (Return)']);
        $collecting = Asset::factory()->create([
            'asset_tag' => 'STILL-COLLECTING',
            'status_id' => $processing->id,
        ]);
        Asset::query()->whereKey($collecting->id)->update(['purchase_date' => null, 'asset_eol_date' => null]);

        $archived = Statuslabel::factory()->archived()->create(['name' => 'Archived (Returned)']);
        $gone = Asset::factory()->create([
            'asset_tag' => 'PICKED-UP-2024',
            'status_id' => $archived->id,
        ]);
        Asset::query()->whereKey($gone->id)->update([
            'decommission_date' => '2024-03-15',
            'purchase_date' => null,
            'asset_eol_date' => null,
        ]);

        $content = $this->actingAs($this->superuser())
            ->get(route('reports.deployments', ['fiscal_year' => 'FY2023-24']))
            ->assertOk()
            ->getContent();

        // The pickup register carries the run date; today's Processing
        // devices have no business on a past year's board.
        $this->assertStringContainsString(e(trans('admin/deployments/general.decom_pickups_title')), $content);
        $this->assertStringContainsString('2024-03-15', $content);
        $this->assertStringNotContainsString('STILL-COLLECTING', $content);
        $this->assertStringNotContainsString(trans('admin/deployments/general.decom_collecting_note'), $content);
    }

    public function test_pickup_csv_streams_the_devices_of_one_run()
    {
        $archived = Statuslabel::factory()->archived()->create(['name' => 'Archived (Donated)']);
        $gone = Asset::factory()->create([
            'asset_tag' => 'CSV-PICKUP-1',
            'status_id' => $archived->id,
        ]);
        Asset::query()->whereKey($gone->id)->update(['decommission_date' => '2024-03-15']);

        $response = $this->actingAs($this->superuser())
            ->get(route('reports.deployments', ['fiscal_year' => 'FY2023-24', 'decom_pickup' => '2024-03-15', 'format' => 'csv']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $this->assertStringContainsString('CSV-PICKUP-1', $response->streamedContent());
    }

    public function test_past_fy_counts_unrefreshed_devices_as_open_planned_backlog()
    {
        // Due in FY2023-24, still active today — never refreshed, so the
        // plan is still open and the board says so.
        $missed = Asset::factory()->create(['asset_tag' => 'MISSED-2324']);
        Asset::query()->whereKey($missed->id)->update([
            'lease_end_date' => '2023-12-01',
            'asset_eol_date' => null,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.deployments', ['fiscal_year' => 'FY2023-24']))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.flow_backlog_note_past', ['count' => 1, 'fy' => 'FY2023-24']))
            ->assertSee('MISSED-2324');
    }

    public function test_bulk_stage_move_gates_planned_devices_without_an_order_line()
    {
        $wave = DeploymentWave::create(['name' => 'Gate Wave', 'fiscal_year' => 'FY2026-27']);
        $planned = \App\Models\DeploymentStage::where('slug', 'planned')->first();
        $ordered = \App\Models\DeploymentStage::where('slug', 'ordered')->first();

        $unlinked = DeploymentItem::create(['wave_id' => $wave->id, 'stage_id' => $planned->id]);
        $orderItem = \App\Models\OrderItem::factory()->create();
        $linked = DeploymentItem::create(['wave_id' => $wave->id, 'stage_id' => $planned->id, 'order_item_id' => $orderItem->id]);

        $this->actingAs($this->superuser())
            ->post(route('deployment-items.bulk-stage'), [
                'item_ids' => [$unlinked->id, $linked->id],
                'stage_id' => $ordered->id,
            ])
            ->assertSessionHas('success', trans('admin/deployments/general.bulk_moved', ['count' => 1, 'stage' => $ordered->name]))
            ->assertSessionHas('warning', trans('admin/deployments/general.bulk_gated', ['count' => 1]));

        // Ordered is a fact from procurement: only the device on a real
        // order line moved; the other stayed in Planned.
        $this->assertSame($planned->id, $unlinked->fresh()->stage_id);
        $this->assertSame($ordered->id, $linked->fresh()->stage_id);
    }

    public function test_bulk_stage_move_flips_the_asset_status_when_the_stage_maps_to_one()
    {
        $wave = DeploymentWave::create(['name' => 'Map Wave', 'fiscal_year' => 'FY2026-27']);
        $arrived = \App\Models\DeploymentStage::where('slug', 'arrived')->first();
        $inventoried = \App\Models\DeploymentStage::where('slug', 'inventoried')->first();

        $target = Statuslabel::factory()->pending()->create(['name' => 'New (Inventoried)']);
        $inventoried->update(['maps_to_status_id' => $target->id]);

        $asset = Asset::factory()->create(['asset_tag' => 'BULK-FLIP']);
        $item = DeploymentItem::create(['wave_id' => $wave->id, 'stage_id' => $arrived->id, 'asset_id' => $asset->id]);

        $this->actingAs($this->superuser())
            ->post(route('deployment-items.bulk-stage'), [
                'item_ids' => [$item->id],
                'stage_id' => $inventoried->id,
            ])
            ->assertSessionHas('success');

        $this->assertSame($inventoried->id, $item->fresh()->stage_id);
        $this->assertSame($target->id, $asset->fresh()->status_id);
    }

    public function test_boards_live_at_top_level_urls_and_old_report_urls_redirect()
    {
        // Elevated modules: the route names survived, the URIs moved.
        $this->assertSame(url('/deployments'), route('reports.deployments'));
        $this->assertSame(url('/procurement'), route('reports.procurement'));

        $user = $this->superuser();
        $this->actingAs($user)->get('/reports/deployments')
            ->assertStatus(301)
            ->assertRedirect('/deployments');
        $this->actingAs($user)->get('/reports/procurement')
            ->assertStatus(301)
            ->assertRedirect('/procurement');
        $this->actingAs($user)->get('/reports/procurement/disposition-grid')
            ->assertStatus(301)
            ->assertRedirect('/procurement/disposition-grid');
    }

    public function test_deployments_read_only_permission_views_but_cannot_write()
    {
        $viewer = User::factory()->create(['permissions' => '{"deployments.view":"1"}']);

        $this->actingAs($viewer)
            ->get(route('reports.deployments'))
            ->assertOk();

        $wave = DeploymentWave::create(['name' => 'Perm Wave', 'fiscal_year' => 'FY2026-27']);
        $item = DeploymentItem::create(['wave_id' => $wave->id]);
        $ordered = \App\Models\DeploymentStage::where('slug', 'ordered')->first();

        $this->actingAs($viewer)
            ->post(route('deployment-items.bulk-stage'), [
                'item_ids' => [$item->id],
                'stage_id' => $ordered->id,
            ])
            ->assertForbidden();
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
