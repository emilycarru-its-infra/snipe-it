<?php

namespace Tests\Feature\LeaseSchedules;

use App\Models\LeaseSchedule;
use App\Models\User;
use Tests\TestCase;

class ScheduleSigningQueueTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_queue_defaults_to_open_stages()
    {
        LeaseSchedule::create([
            'schedule_ref' => '301452-007',
            'lessor' => 'CSI Leasing',
            'lifecycle_stage' => 'awaiting_signature',
            'received_at' => now()->subDays(7)->format('Y-m-d'),
        ]);
        LeaseSchedule::create([
            'schedule_ref' => '301452-005',
            'lessor' => 'CSI Leasing',
            'lifecycle_stage' => 'signed',
            'received_at' => now()->subDays(60)->format('Y-m-d'),
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.schedule-signing'))
            ->assertOk()
            // Open queue shows the awaiting_signature row and hides
            // schedules that have already been signed.
            ->assertSee('301452-007')
            ->assertDontSee('301452-005');
    }

    public function test_queue_with_stage_all_includes_signed()
    {
        LeaseSchedule::create([
            'schedule_ref' => 'ECI20240801-1',
            'lifecycle_stage' => 'signed',
            'received_at' => now()->subDays(30)->format('Y-m-d'),
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.schedule-signing', ['stage' => 'all']))
            ->assertOk()
            ->assertSee('ECI20240801-1');
    }

    public function test_vendor_on_hold_renders_danger_row()
    {
        LeaseSchedule::create([
            'schedule_ref' => '301452-009',
            'lifecycle_stage' => 'awaiting_signature',
            'received_at' => now()->subDays(3)->format('Y-m-d'),
            'vendor_on_hold' => true,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.schedule-signing'))
            ->assertOk()
            ->assertSee('301452-009')
            // The danger class is what the chase view uses to flag Apple-
            // account-on-hold rows.
            ->assertSee('class="danger"', false);
    }

    public function test_mark_signed_stamps_signer_and_advances_stage()
    {
        $schedule = LeaseSchedule::create([
            'schedule_ref' => '301452-010',
            'lifecycle_stage' => 'awaiting_signature',
        ]);

        $signer = $this->superuser();

        $this->actingAs($signer)
            ->post(route('lease-schedules.mark-signed', $schedule))
            ->assertRedirect(route('lease-schedules.show', $schedule));

        $schedule->refresh();
        $this->assertEquals('signed', $schedule->lifecycle_stage);
        $this->assertNotNull($schedule->signed_at);
        $this->assertEquals($signer->id, $schedule->signed_by);
    }

    public function test_dashboard_shows_schedules_awaiting_signature_card()
    {
        LeaseSchedule::create([
            'schedule_ref' => '301452-011',
            'lifecycle_stage' => 'draft',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement'))
            ->assertOk()
            // The unsigned-schedules count now lives in the Reconciling
            // chevron on the pipeline rail.
            ->assertSee(trans('admin/purchase-orders/general.pipeline_reconciling_note', ['pending' => 0, 'schedules' => 1]));
    }

    public function test_create_and_update_endpoints_work()
    {
        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->post(route('lease-schedules.store'), [
                'schedule_ref' => '301452-012',
                'lessor' => 'CSI Leasing',
                'lease_type' => 'Lease to Return',
                'term_months' => 48,
                'received_at' => now()->format('Y-m-d'),
                'lifecycle_stage' => 'draft',
            ])
            ->assertRedirect();

        $schedule = LeaseSchedule::where('schedule_ref', '301452-012')->firstOrFail();

        $this->actingAs($superuser)
            ->patch(route('lease-schedules.update', $schedule), [
                'schedule_ref' => $schedule->schedule_ref,
                'lifecycle_stage' => 'awaiting_signature',
                'vendor_on_hold' => 1,
            ])
            ->assertRedirect();

        $schedule->refresh();
        $this->assertEquals('awaiting_signature', $schedule->lifecycle_stage);
        $this->assertTrue($schedule->vendor_on_hold);
    }
}
