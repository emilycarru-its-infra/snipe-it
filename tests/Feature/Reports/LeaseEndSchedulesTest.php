<?php

namespace Tests\Feature\Reports;

use App\Models\Asset;
use App\Models\CustomField;
use App\Models\LeaseDecision;
use App\Models\Statuslabel;
use App\Models\User;
use Tests\TestCase;

/**
 * The lease-end schedules breakdown on the procurement dashboard: every
 * schedule ending in the scoped FY, with the logged lease decision. A
 * buyout / return / extension decision pulls the schedule out of the
 * pre-approval (refresh) estimate; no decision means refresh-by-default.
 */
class LeaseEndSchedulesTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    /**
     * Put $count devices on $contractId ending $endDate, each costing
     * $unitCost, and return nothing — the dashboard reads them by field.
     */
    private function seedSchedule(string $contractId, string $endDate, int $count, float $unitCost): void
    {
        $contractField = CustomField::where('name', 'Lease Contract ID')->first()
            ?? CustomField::factory()->create(['name' => 'Lease Contract ID']);
        $endField = CustomField::where('name', 'Lease End Date')->first()
            ?? CustomField::factory()->create(['name' => 'Lease End Date']);

        $active = Statuslabel::factory()->rtd()->create();

        for ($i = 0; $i < $count; $i++) {
            $asset = Asset::factory()->create([
                'status_id' => $active->id,
                'purchase_cost' => $unitCost,
            ]);
            Asset::query()->whereKey($asset->id)->update([
                $contractField->db_column => $contractId,
                $endField->db_column => $endDate,
            ]);
        }
    }

    public function test_dashboard_lists_ending_schedules_and_defaults_to_refresh()
    {
        $this->seedSchedule('ECI20221201', '2026-12-31', 2, 1111.11);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertSee('ECI20221201')
            ->assertSee('Macquarie')
            ->assertSee('2026-12-31')
            ->assertSee(trans('admin/purchase-orders/general.lease_end_refresh_planned'))
            // 2 × $1,111.11 lands in both the schedule row and the
            // pre-approval tile.
            ->assertSee('$2,222.22');
    }

    public function test_decided_schedule_stays_in_the_preapproval_estimate()
    {
        $this->seedSchedule('ECI20221201', '2026-12-31', 2, 1111.11);

        LeaseDecision::factory()->create([
            'contract_reference' => 'ECI20221201',
            'decision_type' => 'buyout',
            'status' => 'approved',
            'decision_date' => '2026-12-31',
            'notes' => 'Lease-to-own; device needs re-assessed for the Faculty Laptop program.',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            // The schedule shows, carrying its decision and note…
            ->assertSee('ECI20221201')
            ->assertSee(trans('admin/lease-decisions/general.type_buyout'))
            ->assertSee('Faculty Laptop program')
            // …and it's flagged as re-assessed, listed under the decided sub-total…
            ->assertSee(trans('admin/purchase-orders/general.lease_end_reassess'))
            ->assertSee(trans('admin/purchase-orders/general.lease_end_totals_decided'))
            // …but its full value is still pre-approved: 2 × $1,111.11 drives
            // the estimate, the lease's original value rolls forward whatever
            // the renewal decision is.
            ->assertSee('$2,222.22');
    }

    public function test_replace_decision_keeps_schedule_in_the_estimate()
    {
        $this->seedSchedule('ECI20220901', '2026-09-01', 1, 500.00);

        LeaseDecision::factory()->create([
            'contract_reference' => 'ECI20220901',
            'decision_type' => 'replace',
            'status' => 'approved',
            'decision_date' => '2026-09-01',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertSee('ECI20220901')
            ->assertSee(trans('admin/lease-decisions/general.type_replace'))
            ->assertSee('$500.00');
    }

    public function test_disposed_devices_drop_from_the_count_but_keep_their_budget()
    {
        // Two devices still active on the schedule…
        $this->seedSchedule('ECI20990101', '2026-12-31', 2, 1000.00);

        // …and one already returned (archived) — its body leaves the
        // headcount, but its cost stays in the pre-approval envelope.
        $contractField = CustomField::where('name', 'Lease Contract ID')->first();
        $endField = CustomField::where('name', 'Lease End Date')->first();
        $archived = Statuslabel::factory()->archived()->create();
        $returned = Asset::factory()->create([
            'status_id' => $archived->id,
            'purchase_cost' => 777.00,
        ]);
        Asset::query()->whereKey($returned->id)->update([
            $contractField->db_column => 'ECI20990101',
            $endField->db_column => '2026-12-31',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            // The full schedule value — all three devices — drives the estimate.
            ->assertSee('$2,777.00')
            // …but only the two active devices are counted.
            ->assertSee('2 devices')
            ->assertDontSee('3 devices');
    }

    public function test_schedule_outside_selected_fy_is_hidden()
    {
        $this->seedSchedule('ECI20221201', '2026-12-31', 1, 100.00);
        $this->seedSchedule('ECI20200401', '2025-04-30', 1, 100.00);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertSee('ECI20221201')
            ->assertDontSee('ECI20200401');
    }
}
