<?php

namespace Tests\Feature\Contracts;

use App\Helpers\Helper;
use App\Models\Contract;
use App\Models\User;
use Tests\TestCase;

/**
 * /contracts is one page: tiles, charts, the drill-down reports and the
 * register table. It used to be two — a dashboard at /reports/contracts and a
 * bare table here — so these cover the merge: the old URLs still resolve, the
 * page opens on the current fiscal year the way /procurement does, the filters
 * narrow the charts as well as the table, and the drill-downs answer both as
 * standalone pages and as inline embeds.
 */
class ContractsPageTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_page_renders_with_tiles_charts_reports_and_table()
    {
        Contract::factory()->create(['fiscal_year' => 'FY2024-25']);

        $this->actingAs($this->superuser())
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee(trans('admin/contracts/general.tile_all'))
            ->assertSee(trans('admin/contracts/general.tile_renewal_series'))
            ->assertSee('contractsFyChart', false)
            // The report sections are lazy-loaded, so the page ships their
            // embed URLs rather than their rows.
            ->assertSee(route('contracts.reports.serial-register', ['embed' => 1], false), false)
            ->assertSee(route('api.contracts.index', [], false), false);
    }

    public function test_page_opens_on_the_current_fiscal_year()
    {
        // Matches /procurement, and moves with the calendar rather than
        // being pinned to a year.
        $current = Helper::currentFiscalYear();
        Contract::factory()->create(['fiscal_year' => $current]);
        Contract::factory()->create(['fiscal_year' => 'FY2023-24']);

        $this->actingAs($this->superuser())
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('value="'.$current.'" selected', false)
            ->assertDontSee('value="all" selected', false);
    }

    public function test_picker_spans_fy2024_25_through_next_year_and_opens_on_the_current_fy()
    {
        // The register starts at FY2024-25: the picker runs from there
        // through next fiscal year whether or not a year holds rows, and
        // opens on the current FY — the zero-filled chart makes an empty
        // year read as "none this year yet" rather than "no contracts".
        Contract::factory()->create(['fiscal_year' => 'FY2024-25']);

        $current = Helper::currentFiscalYear();
        $next = 'FY'.((int) substr($current, 2, 4) + 1).'-'.substr((string) ((int) substr($current, 2, 4) + 2), -2);

        $this->actingAs($this->superuser())
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('value="FY2024-25"', false)
            ->assertSee('value="'.$next.'"', false)
            ->assertSee('value="'.$current.'" selected', false);
    }

    public function test_all_fiscal_years_is_the_opt_out()
    {
        Contract::factory()->create(['fiscal_year' => 'FY2024-25']);

        $response = $this->actingAs($this->superuser())
            ->get(route('contracts.index', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee('value="all" selected', false);

        $this->assertNull($response->viewData('selectedFy'));
    }

    public function test_filters_narrow_the_charts_not_only_the_table()
    {
        // The whole page reads against one filter set. A theme filter has to
        // redraw the charts too, or the numbers on screen disagree.
        Contract::factory()->create(['fiscal_year' => 'FY2024-25', 'theme' => 'InfoSec', 'total_cost' => 100]);
        Contract::factory()->create(['fiscal_year' => 'FY2024-25', 'theme' => 'Networking', 'total_cost' => 900]);

        $unfiltered = $this->actingAs($this->superuser())
            ->get(route('contracts.index', ['fiscal_year' => 'all']))
            ->assertOk()
            ->viewData('charts');

        $filtered = $this->actingAs($this->superuser())
            ->get(route('contracts.index', ['fiscal_year' => 'all', 'theme' => 'InfoSec']))
            ->assertOk()
            ->viewData('charts');

        // Spend-by-FY drops its own dimension but honours the theme filter.
        $this->assertSame(1000.0, (float) array_sum($unfiltered['fyValues']));
        $this->assertSame(100.0, (float) array_sum($filtered['fyValues']));

        // The theme chart keeps every bar — filtering it by its own axis
        // would leave one — and marks which is selected.
        $this->assertCount(2, $filtered['themeLabels']);
        $this->assertSame('InfoSec', $filtered['themeSelected']);
    }

    public function test_fiscal_year_scopes_the_page()
    {
        Contract::factory()->create(['fiscal_year' => 'FY2024-25']);

        $this->actingAs($this->superuser())
            ->get(route('contracts.index', ['fiscal_year' => 'FY2024-25']))
            ->assertOk()
            ->assertSee('value="FY2024-25" selected', false);
    }

    public function test_renewal_series_tile_counts_synthesized_rows_only()
    {
        // A migration seeds a system "Unattributed" contract, itself
        // synthesized, so both counts are measured as deltas. Both reads pin
        // fiscal_year=all: the default FY resolves against whatever years
        // hold data, which changes as this test adds rows.
        $all = ['fiscal_year' => 'all'];
        $before = $this->actingAs($this->superuser())->get(route('contracts.index', $all))->assertOk();

        Contract::factory()->count(2)->create(['is_synthesized' => false]);
        Contract::factory()->create(['is_synthesized' => true]);

        $after = $this->actingAs($this->superuser())->get(route('contracts.index', $all))->assertOk();

        $this->assertSame(3, $after->viewData('totalCount') - $before->viewData('totalCount'));
        $this->assertSame(1, $after->viewData('renewalSeriesCount') - $before->viewData('renewalSeriesCount'));
    }

    public function test_spend_excludes_synthesized_rows()
    {
        // A renewal series row is a grouping, not a contract — counting its
        // cost would double the spend of the children it groups.
        $all = ['fiscal_year' => 'all'];
        $before = $this->actingAs($this->superuser())->get(route('contracts.index', $all))->assertOk();

        Contract::factory()->create(['is_synthesized' => false, 'total_cost' => 100]);
        Contract::factory()->create(['is_synthesized' => true, 'total_cost' => 100]);

        $after = $this->actingAs($this->superuser())->get(route('contracts.index', $all))->assertOk();

        $this->assertSame(100.0, $after->viewData('totalCost') - $before->viewData('totalCost'));
    }

    /**
     * @dataProvider reportRoutes
     */
    public function test_report_renders_as_a_page_and_as_an_embed(string $route)
    {
        Contract::factory()->create(['is_synthesized' => true]);

        $this->actingAs($this->superuser())->get(route($route))->assertOk();

        // Embeds are the bare table, injected into the page via innerHTML —
        // they must not drag the whole layout in with them.
        $this->actingAs($this->superuser())
            ->get(route($route, ['embed' => 1]))
            ->assertOk()
            ->assertDontSee('<html', false);
    }

    public static function reportRoutes(): array
    {
        return [
            'expiring soon'    => ['contracts.reports.expiring-soon'],
            'renewal series'   => ['contracts.reports.renewal-series'],
            'by area'          => ['contracts.reports.by-area'],
            'by provider'      => ['contracts.reports.by-provider'],
            'serial register'  => ['contracts.reports.serial-register'],
            'naming violators' => ['contracts.reports.naming-violators'],
            'stale'            => ['contracts.reports.stale'],
        ];
    }

    /**
     * @dataProvider movedReportPaths
     */
    public function test_old_report_urls_redirect(string $oldPath, string $newRoute)
    {
        $this->actingAs($this->superuser())
            ->get($oldPath)
            ->assertRedirect(route($newRoute));
    }

    public static function movedReportPaths(): array
    {
        return [
            'dashboard'        => ['/reports/contracts', 'contracts.index'],
            'expiring soon'    => ['/reports/contracts/expiring-soon', 'contracts.reports.expiring-soon'],
            // The umbrella report kept its content and lost its name: it was
            // one of two things called "umbrella" in this module.
            'umbrellas'        => ['/reports/contracts/umbrellas', 'contracts.reports.renewal-series'],
            'by theme'         => ['/reports/contracts/by-theme', 'contracts.reports.by-area'],
            'by provider'      => ['/reports/contracts/by-provider', 'contracts.reports.by-provider'],
            'serial register'  => ['/reports/contracts/serial-register', 'contracts.reports.serial-register'],
            'naming violators' => ['/reports/contracts/naming-violators', 'contracts.reports.naming-violators'],
            'stale'            => ['/reports/contracts/stale', 'contracts.reports.stale'],
        ];
    }

    public function test_renewal_series_tile_matches_the_rows_its_link_returns()
    {
        // Each count tile is a filter over the table below it, so the number
        // has to equal what clicking it leaves behind. Keying this tile off
        // source='synthesized' would not: the system "Unattributed" row is
        // synthesized but carries source='manual'.
        Contract::factory()->count(2)->create(['is_synthesized' => true, 'source' => 'synthesized']);
        Contract::factory()->create(['is_synthesized' => true, 'source' => 'manual']);
        Contract::factory()->create(['is_synthesized' => false]);

        $user = $this->superuser();

        $tile = $this->actingAs($user)
            ->get(route('contracts.index', ['fiscal_year' => 'all']))
            ->assertOk()
            ->viewData('renewalSeriesCount');

        $rows = $this->actingAsForApi($user)
            ->getJson(route('api.contracts.index', ['synthesized_only' => 'true']))
            ->assertOk()
            ->json('total');

        $this->assertSame($tile, $rows);
    }

    public function test_page_renders_without_the_reports_permission()
    {
        // Viewing contracts and reporting on them are separate grants. Anyone
        // with only the former gets the tiles, filters and register table;
        // the charts and the report sections are simply absent, not a 500.
        $viewer = User::factory()->create([
            'permissions' => json_encode(['contracts.view' => '1']),
        ]);

        $this->actingAs($viewer)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee(trans('admin/contracts/general.tile_all'))
            ->assertDontSee('contractsFyChart', false)
            ->assertDontSee('contracts-report-body', false);
    }

    public function test_by_theme_redirects_to_by_area()
    {
        // "Theme" was the old name; the slug shipped before the rename.
        $this->actingAs($this->superuser())
            ->get('/contracts/by-theme')
            ->assertRedirect(route('contracts.reports.by-area'));
    }

    public function test_contracts_table_has_no_dead_select_column()
    {
        // Contracts have no bulk actions, so a select-all column would offer
        // a selection nothing can act on.
        $layout = json_decode(\App\Presenters\ContractPresenter::dataTableLayout(), true);

        $this->assertNotContains('checkbox', array_column($layout, 'field'));
    }

    public function test_report_paths_do_not_shadow_the_contract_show_route()
    {
        $contract = Contract::factory()->create();

        $this->actingAs($this->superuser())
            ->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSee($contract->name);
    }
}
