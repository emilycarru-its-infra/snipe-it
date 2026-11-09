<?php

namespace Tests\Feature\Orders;

use App\Models\CsiSchedule;
use App\Models\ProcurementSetting;
use App\Models\User;
use App\Services\SupplierAccounts;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ConfirmedOrderingWindowsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09');
        ProcurementSetting::put('lease_open_schedules', '[]');
    }

    private function windows(): array
    {
        return [
            ['schedule' => '100000-009', 'type' => 'return', 'scope' => 'admin',
                'ordering_until' => '2026-12-31', 'planned_commencement' => '2027-01-01',
                'bpo_reference' => '100000-009.1', 'bpo_valid_until' => '2027-01-01',
                'confirmed_at' => '2026-11-09', 'source_reference' => 'Lessor confirmation'],
            ['schedule' => '100000-010', 'type' => 'own', 'scope' => 'curriculum',
                'ordering_until' => '2026-12-31', 'planned_commencement' => '2027-01-01',
                'bpo_reference' => '100000-010.1', 'bpo_valid_until' => '2027-01-01',
                'confirmed_at' => '2026-11-09', 'source_reference' => 'Lessor confirmation'],
        ];
    }

    public function test_a_forecast_and_unexpired_signed_lease_are_not_open_windows(): void
    {
        CsiSchedule::create(['schedule_name' => '100000-007', 'term_end_date' => '2030-01-01']);
        $this->assertSame([], CsiSchedule::openScheduleNames());
        $this->assertNotEmpty(CsiSchedule::projectedPair());
        $this->assertSame(['return' => null, 'own' => null], CsiSchedule::openPair());
    }

    public function test_extension_keeps_schedule_identity_and_labels_financing_budget_and_bpo(): void
    {
        ProcurementSetting::put('lease_open_schedules', json_encode($this->windows()));
        $this->assertSame(['100000-009', '100000-010'], CsiSchedule::openScheduleNames());
        $this->assertSame('100000-010', CsiSchedule::scheduleForAccount('lease_curriculum'));
        $this->assertSame(['100000-010'], SupplierAccounts::schedulesFor('lease_curriculum', CsiSchedule::openScheduleNames()));
        $this->assertSame('100000-010.1', CsiSchedule::bpoReference('100000-010'));
        $label = CsiSchedule::scheduleLabel('100000-010');
        $this->assertStringContainsString('Lease to own', $label);
        $this->assertStringContainsString('Curriculum', $label);
        $this->assertStringContainsString('BPO 100000-010.1', $label);
    }

    public function test_ordering_expiry_is_distinct_from_bpo_validity_and_commencement(): void
    {
        ProcurementSetting::put('lease_open_schedules', json_encode($this->windows()));
        Carbon::setTestNow('2026-12-31');
        $this->assertCount(2, CsiSchedule::openScheduleNames());
        Carbon::setTestNow('2027-01-01');
        $this->assertSame([], CsiSchedule::openScheduleNames());
        $this->assertSame('100000-009.1', CsiSchedule::bpoReference('100000-009'));
    }

    public function test_explicit_closure_and_future_opening_are_not_selectable(): void
    {
        $windows = $this->windows();
        $windows[0]['closed_at'] = '2026-11-08';
        $windows[1]['ordering_from'] = '2026-12-01';
        ProcurementSetting::put('lease_open_schedules', json_encode($windows));
        $this->assertSame([], CsiSchedule::openScheduleNames());
        $this->assertCount(2, CsiSchedule::orderingWindows(false));
    }

    public function test_api_records_the_confirmation_without_rewriting_mirror_term_dates(): void
    {
        $mirror = CsiSchedule::create(['schedule_name' => '100000-009', 'term_start_date' => '2026-10-01', 'term_end_date' => '2030-09-30']);
        Passport::actingAs(User::factory()->superuser()->create());
        $this->putJson(route('api.procurement.cadence'), ['open_schedules' => $this->windows()])
            ->assertOk()->assertJsonPath('payload.open_pair.return', '100000-009')
            ->assertJsonPath('payload.open_schedules.1.bpo_reference', '100000-010.1');
        $this->assertSame('2026-10-01', $mirror->fresh()->term_start_date->toDateString());
        $this->assertSame('2030-09-30', $mirror->fresh()->term_end_date->toDateString());
    }

    public function test_invalid_window_is_rejected_without_saving_part_of_the_request(): void
    {
        Passport::actingAs(User::factory()->superuser()->create());
        $windows = $this->windows();
        $windows[0]['ordering_from'] = '2027-02-01';
        $this->putJson(route('api.procurement.cadence'), ['open_schedules' => $windows])
            ->assertOk()->assertJsonPath('status', 'error')
            ->assertJsonPath('messages.open_schedules.0', 'Ordering start must not be after its end.');
        $this->assertSame([], CsiSchedule::orderingWindows(false));
    }

    public function test_unprivileged_api_caller_cannot_set_open_schedules(): void
    {
        Passport::actingAs(User::factory()->create());
        $this->putJson(route('api.procurement.cadence'), ['open_schedules' => $this->windows()])->assertForbidden();
        $this->assertSame([], CsiSchedule::orderingWindows(false));
    }
}
