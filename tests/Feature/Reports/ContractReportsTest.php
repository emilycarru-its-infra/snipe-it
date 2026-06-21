<?php

namespace Tests\Feature\Reports;

use App\Helpers\Helper;
use App\Models\Contract;
use App\Models\User;
use Tests\TestCase;

/**
 * The contracts reports dashboard (/reports/contracts) opens on the current
 * fiscal year. When no contract carries the current FY yet, it falls back to
 * the most recent FY that actually has contracts rather than silently dropping
 * to the all-time view (the previous behaviour, which read as "not defaulting"
 * because the page showed every year). `?fiscal_year=all` is the opt-out.
 */
class ContractReportsTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_dashboard_defaults_to_current_fiscal_year_when_present()
    {
        $current = Helper::currentFiscalYear();
        Contract::factory()->create(['fiscal_year' => $current]);
        Contract::factory()->create(['fiscal_year' => 'FY2023-24']);

        $this->actingAs($this->superuser())
            ->get(route('reports.contracts'))
            ->assertOk()
            ->assertSee('value="'.$current.'" selected', false)
            ->assertDontSee('value="all" selected', false);
    }

    public function test_dashboard_falls_back_to_most_recent_fy_when_current_is_empty()
    {
        // No contracts in the current FY; the latest with data is FY2024-25.
        Contract::factory()->create(['fiscal_year' => 'FY2023-24']);
        Contract::factory()->create(['fiscal_year' => 'FY2024-25']);

        $this->actingAs($this->superuser())
            ->get(route('reports.contracts'))
            ->assertOk()
            ->assertSee('value="FY2024-25" selected', false)
            ->assertDontSee('value="all" selected', false);
    }

    public function test_explicit_all_opts_out_of_the_fiscal_year_default()
    {
        Contract::factory()->create(['fiscal_year' => 'FY2024-25']);

        $this->actingAs($this->superuser())
            ->get(route('reports.contracts', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee('value="all" selected', false);
    }
}
