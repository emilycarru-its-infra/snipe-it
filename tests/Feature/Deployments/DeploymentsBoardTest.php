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

    public function test_waves_index_lists_every_wave_with_the_inline_catalogs()
    {
        DeploymentWave::create(['name' => 'FY26 Faculty Refresh', 'slug' => 'fy26-faculty', 'fiscal_year' => 'FY2026-27']);
        DeploymentWave::create(['name' => 'FY24 Lab Rollout', 'slug' => 'fy24-lab', 'fiscal_year' => 'FY2024-25']);

        $this->actingAs($this->superuser())
            ->get(route('deployment-waves.index'))
            ->assertOk()
            ->assertSee('FY26 Faculty Refresh')
            ->assertSee('FY24 Lab Rollout')
            // The catalogs are managed here, not behind a Configure page.
            ->assertSee(trans('admin/deployments/general.catalog_types'))
            ->assertSee(trans('admin/deployments/general.catalog_stages'));
    }

    public function test_decommissioning_has_a_page_of_its_own()
    {
        $this->actingAs($this->superuser())
            ->get(route('deployments.decommissioning'))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.decom_title'));
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

        // The lane lives on its own page now, not at the bottom of the board.
        $this->actingAs($this->superuser())
            ->get(route('reports.deployments'))
            ->assertOk()
            ->assertDontSee('DECOM-1');

        $this->actingAs($this->superuser())
            ->get(route('deployments.decommissioning'))
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
            ->get(route('deployments.decommissioning'))
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

        // Waves-only board: the lookahead device is counted in the pointer
        // to Forecast rather than rendered as a row.
        $this->actingAs($this->superuser())
            ->get(route('reports.deployments', ['fiscal_year' => $nextFy]))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.flow_backlog_pointer', ['count' => 1]))
            ->assertDontSee('PLAN-2728');
    }

    public function test_unfunded_fleet_box_lives_on_procurement_and_fleet_health_not_deployments()
    {
        $legacy = Statuslabel::factory()->rtd()->create(['name' => 'Active (Legacy)']);
        Asset::factory()->create([
            'asset_tag' => 'LEGACY-1',
            'status_id' => $legacy->id,
            'purchase_date' => '2016-08-01',
        ]);
        $buyout = Statuslabel::factory()->rtd()->create(['name' => 'Active (Buyouts)']);
        Asset::factory()->create([
            'asset_tag' => 'BUYOUT-1',
            'status_id' => $buyout->id,
            'purchase_date' => '2019-08-01',
        ]);

        $user = $this->superuser();

        // Not relevant on the operational board — planning/exec scope only.
        $this->actingAs($user)
            ->get(route('reports.deployments'))
            ->assertOk()
            ->assertDontSee(trans('admin/deployments/general.legacy_box_title'));

        // Both families, each sliceable.
        $this->actingAs($user)
            ->get(route('reports.procurement'))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.legacy_box_title'))
            ->assertSee('Active (Legacy)')
            ->assertSee('Active (Buyouts)');

        $this->actingAs($user)
            ->get(route('reports.fleet-health'))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.legacy_box_title'))
            ->assertSee('Active (Buyouts)');
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

        // Order lines with no wave are planning's business — the Incoming
        // orders table there carries them; the board stays waves-only.
        $boardContent = $this->actingAs($this->superuser())
            ->get(route('reports.deployments'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('PVTEST99', $boardContent);

        $content = $this->actingAs($this->superuser())
            ->get(route('deployments.planning'))
            ->assertOk()
            ->getContent();

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
            ->get(route('deployments.decommissioning', ['fiscal_year' => 'FY2023-24']))
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

        // The board is waves-only now: the missed device is counted in the
        // pointer to Forecast, not rendered as a row.
        $this->actingAs($this->superuser())
            ->get(route('reports.deployments', ['fiscal_year' => 'FY2023-24']))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.flow_backlog_pointer', ['count' => 1]))
            ->assertDontSee('MISSED-2324');
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

    public function test_old_hub_url_redirects_and_board_carries_stage_tabbed_reports()
    {
        $user = $this->superuser();

        $this->actingAs($user)
            ->get('/procurement/hub')
            ->assertRedirect(route('reports.procurement'));

        $this->actingAs($user)
            ->get(route('reports.procurement'))
            ->assertOk()
            ->assertSee('class="pr-pill-col" data-report-stage="budgeting"', false)
            ->assertSee('id="pr-reports"', false);
    }

    public function test_read_only_procurement_viewer_gets_no_write_controls()
    {
        $viewer = User::factory()->create(['permissions' => '{"procurement.view":"1"}']);

        $this->actingAs($viewer)
            ->get(route('reports.procurement'))
            ->assertOk()
            ->assertDontSee(trans('admin/store/general.go_store_admin'))
            ->assertDontSee('id="approversModal"', false);
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
        $assetsNav = strpos($content, 'topnav-item topnav-assets');
        $deployNav = strpos($content, 'topnav-item topnav-deployments');
        $procNav = strpos($content, 'topnav-item topnav-procurement');
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

    /**
     * OrderItem::item is a morphTo that can point at more than Assets. An
     * order line for a Component used to 500 the whole board, because the
     * eager load asked every item type for a model() relation only Assets
     * have.
     */
    public function test_board_survives_an_order_line_for_a_non_asset()
    {
        $order = \App\Models\Order::factory()->create([
            'fiscal_year' => 'FY2026-27',
            'status' => 'ordered',
            'is_planned' => false,
        ]);
        $component = \App\Models\Component::factory()->create(['name' => 'Odd Part']);
        \App\Models\OrderItem::create([
            'order_id' => $order->id,
            'item_type' => \App\Models\Component::class,
            'item_id' => $component->id,
            'description' => 'Odd Part',
            'quantity' => 1,
            'unit_cost' => 10,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('deployments.planning', ['fiscal_year' => 'FY2026-27']))
            ->assertOk();
    }

    public function test_waves_page_hosts_the_staffing_blackouts_table()
    {
        \App\Models\StaffBlackout::create([
            'user_id' => User::factory()->create()->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-05',
            'reason' => 'Vacation',
            'source' => 'manual',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('deployment-waves.index'))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.blackouts_title'))
            ->assertSee('Vacation');

        // The standalone page is gone; its URL folds into the waves page.
        $this->actingAs($this->superuser())
            ->get(route('deployments.blackouts.index'))
            ->assertRedirect(route('deployment-waves.index'));
    }

    /**
     * The timeline is a day-precise Gantt: a week-and-a-half arrival window
     * must render as a sliver of the axis, not a month-wide slab, and the
     * range captions carry actual days rather than month-year strings that
     * read like dates.
     */
    public function test_timeline_positions_bars_by_day_not_by_month()
    {
        $wave = DeploymentWave::create([
            'name' => 'Gantt Wave',
            'fiscal_year' => 'FY2026-27',
            'arrival_window_start' => '2026-09-08',
            'arrival_window_end' => '2026-09-18',
            'target_start_date' => '2026-09-08',
            'target_end_date' => '2026-10-02',
        ]);

        $timeline = (new \App\Services\Deployments\DeploymentTimeline)->build(
            DeploymentWave::whereKey($wave->id)->get()
        );

        // Grid spans Sep 1 – Oct 31 (61 days). The 11-day arrival window is
        // ~18% wide, nowhere near the ~49% a whole-month slab would be.
        $this->assertCount(2, $timeline['months']);
        $arrival = $timeline['rows'][0]['arrival'];
        $this->assertEqualsWithDelta(11 / 61 * 100, $arrival['widthPct'], 0.1);
        $this->assertEqualsWithDelta(7 / 61 * 100, $arrival['offsetPct'], 0.1);
        $this->assertSame('Sep 8 – Sep 18', $arrival['label']);
        $this->assertSame('Sep 8 – Oct 2', $timeline['rows'][0]['deploy']['label']);

        // Month columns carry their own axis positions for the gridlines.
        $this->assertSame(0.0, $timeline['months'][0]['offsetPct']);
        $this->assertEqualsWithDelta(30 / 61 * 100, $timeline['months'][1]['offsetPct'], 0.1);

        // And the page renders the grid.
        $this->actingAs($this->superuser())
            ->get(route('deployments.planning', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertSee('gantt-gridlines', false)
            ->assertSee('Sep 8 – Sep 18');
    }

    /**
     * The dedicated Update page is gone: every human-typed wave field edits
     * in place on the show page, one field per request, asset-page style.
     */
    public function test_wave_fields_edit_in_place_on_the_show_page()
    {
        $wave = DeploymentWave::create(['name' => 'Inline Wave', 'fiscal_year' => 'FY2026-27']);
        $admin = $this->superuser();

        $content = $this->actingAs($admin)
            ->get(route('deployment-waves.show', $wave))
            ->assertOk()
            ->getContent();

        // Pencils and their single-field forms are on the page; the old
        // edit-page link is not (the route itself no longer exists).
        $this->assertStringContainsString('wave-inline-pencil', $content);
        $this->assertStringContainsString('wave-inline-'.$wave->id.'-notes-form', $content);
        $this->assertStringNotContainsString('/deployments/waves/'.$wave->id.'/edit', $content);

        $this->actingAs($admin)
            ->patch(route('deployment-waves.update', $wave), [
                'field' => 'notes', 'value' => 'Staged in B1120 first',
            ])
            ->assertRedirect(route('deployment-waves.show', $wave));
        $this->assertSame('Staged in B1120 first', $wave->fresh()->notes);

        // Blanking an optional field stores null rather than an empty string.
        $this->actingAs($admin)
            ->patch(route('deployment-waves.update', $wave), ['field' => 'notes', 'value' => '']);
        $this->assertNull($wave->fresh()->notes);
    }

    public function test_inline_wave_edit_rejects_fields_off_the_whitelist_and_bad_values()
    {
        $wave = DeploymentWave::create(['name' => 'Guarded Wave', 'slug' => 'guarded-wave']);
        $admin = $this->superuser();

        // slug is system-owned; the endpoint refuses to touch it.
        $this->actingAs($admin)
            ->patch(route('deployment-waves.update', $wave), ['field' => 'slug', 'value' => 'hijacked'])
            ->assertSessionHas('error');
        $this->assertSame('guarded-wave', $wave->fresh()->slug);

        // A value the model rules reject bounces without saving.
        $this->actingAs($admin)
            ->patch(route('deployment-waves.update', $wave), ['field' => 'location_id', 'value' => '999999'])
            ->assertSessionHasErrors();
        $this->assertNull($wave->fresh()->location_id);

        // No deployments.edit permission, no edit.
        $viewer = User::factory()->create();
        $this->actingAs($viewer)
            ->patch(route('deployment-waves.update', $wave), ['field' => 'notes', 'value' => 'nope'])
            ->assertForbidden();
    }
}
