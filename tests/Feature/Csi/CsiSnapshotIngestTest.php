<?php

namespace Tests\Feature\Csi;

use App\Models\CsiInvoice;
use App\Models\CsiSchedule;
use App\Models\User;
use Tests\TestCase;

class CsiSnapshotIngestTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_requires_permission()
    {
        $this->actingAsForApi(User::factory()->create())
            ->postJson(route('api.csi.snapshot'), ['entity' => 'invoices', 'items' => []])
            ->assertForbidden();
    }

    public function test_ingests_invoices_keyed_by_invoice_number()
    {
        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.csi.snapshot'), [
                'entity' => 'invoices',
                'items' => [
                    ['csi_invoice_number' => 'AJ7FG1T', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'invoice_date' => '2026-06-11', 'amount' => 12509.70, 'currency' => 'CAD', 'raw' => ['Foo' => 'Bar']],
                    ['csi_invoice_number' => 'AJ7XC8E', 'lease_number' => '301452', 'invoice_date' => '2026-06-16', 'amount' => 16320.16],
                ],
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->assertEquals(2, CsiInvoice::count());
        $inv = CsiInvoice::where('csi_invoice_number', 'AJ7FG1T')->first();
        $this->assertEquals('301452-007', $inv->schedule_name);
        $this->assertEquals(12509.70, (float) $inv->amount);
        $this->assertEquals('Bar', $inv->raw['Foo']);
        $this->assertNotNull($inv->last_seen_at);
    }

    public function test_snapshot_is_idempotent_and_updates_in_place()
    {
        $actor = $this->actingAsForApi($this->superuser());
        $base = ['entity' => 'invoices', 'items' => [['csi_invoice_number' => 'AJ7FG1T', 'amount' => 100.00]]];

        $actor->postJson(route('api.csi.snapshot'), $base)->assertOk();
        $actor->postJson(route('api.csi.snapshot'), [
            'entity' => 'invoices',
            'items' => [['csi_invoice_number' => 'AJ7FG1T', 'amount' => 12509.70]],
        ])->assertOk();

        $this->assertEquals(1, CsiInvoice::count());
        $this->assertEquals(12509.70, (float) CsiInvoice::first()->amount);
    }

    public function test_ingests_schedules_keyed_by_schedule_name()
    {
        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.csi.snapshot'), [
                'entity' => 'schedules',
                'items' => [
                    ['schedule_name' => '301452-007', 'lease_number' => '301452', 'term_start_date' => '2026-06-18', 'term_end_date' => '2030-06-18', 'rent' => 2979.44, 'tax' => 0, 'currency' => 'CAD', 'payment_frequency' => 'Yearly'],
                ],
            ])
            ->assertOk();

        $sched = CsiSchedule::where('schedule_name', '301452-007')->first();
        $this->assertNotNull($sched);
        $this->assertEquals('301452', $sched->lease_number);
        $this->assertEquals(2979.44, (float) $sched->rent);
    }

    public function test_rejects_unknown_entity()
    {
        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.csi.snapshot'), ['entity' => 'bogus', 'items' => []])
            ->assertOk()
            ->assertStatusMessageIs('error');
    }
}
