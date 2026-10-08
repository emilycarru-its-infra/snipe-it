<?php

namespace Tests\Feature\Csi;

use App\Models\CsiInprocessAsset;
use App\Models\CsiInvoice;
use App\Models\CsiInvoiceAsset;
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
                    ['csi_invoice_number' => 'TESTAJ3', 'lease_number' => '100000', 'schedule_name' => '100000-007', 'invoice_date' => '2026-06-11', 'amount' => 12509.70, 'currency' => 'CAD', 'raw' => ['Foo' => 'Bar']],
                    ['csi_invoice_number' => 'TESTAJ2', 'lease_number' => '100000', 'invoice_date' => '2026-06-16', 'amount' => 16320.16],
                ],
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->assertEquals(2, CsiInvoice::count());
        $inv = CsiInvoice::where('csi_invoice_number', 'TESTAJ3')->first();
        $this->assertEquals('100000-007', $inv->schedule_name);
        $this->assertEquals(12509.70, (float) $inv->amount);
        $this->assertEquals('Bar', $inv->raw['Foo']);
        $this->assertNotNull($inv->last_seen_at);
    }

    public function test_snapshot_is_idempotent_and_updates_in_place()
    {
        $actor = $this->actingAsForApi($this->superuser());
        $base = ['entity' => 'invoices', 'items' => [['csi_invoice_number' => 'TESTAJ3', 'amount' => 100.00]]];

        $actor->postJson(route('api.csi.snapshot'), $base)->assertOk();
        $actor->postJson(route('api.csi.snapshot'), [
            'entity' => 'invoices',
            'items' => [['csi_invoice_number' => 'TESTAJ3', 'amount' => 12509.70]],
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
                    ['schedule_name' => '100000-007', 'lease_number' => '100000', 'term_start_date' => '2026-06-18', 'term_end_date' => '2030-06-18', 'rent' => 2979.44, 'tax' => 0, 'currency' => 'CAD', 'payment_frequency' => 'Yearly'],
                ],
            ])
            ->assertOk();

        $sched = CsiSchedule::where('schedule_name', '100000-007')->first();
        $this->assertNotNull($sched);
        $this->assertEquals('100000', $sched->lease_number);
        $this->assertEquals(2979.44, (float) $sched->rent);
    }

    public function test_ingests_invoice_asset_lines_per_device()
    {
        $actor = $this->actingAsForApi($this->superuser());
        $line = [
            'csi_invoice_number' => 'RT0001',
            'csi_asset_id' => 501,
            'serial' => 'SN-A',
            'lease_number' => '100000',
            'schedule_name' => '100000-007',
            'period_start_date' => '2026-07-01',
            'period_end_date' => '2026-07-31',
            'rent' => 100.00,
            'period_rent' => 100.00,
            'tax_gst' => 5.00,
            'tax_pst' => 7.00,
            'tax_other' => 0,
            'tax_rate' => 0.12,
        ];

        $actor->postJson(route('api.csi.snapshot'), [
            'entity' => 'invoice_assets',
            'items' => [
                $line,
                // Two unserialized lines on one invoice stay distinct by CSI asset id.
                ['csi_invoice_number' => 'RT0001', 'csi_asset_id' => 502, 'serial' => 'N/A', 'period_rent' => 10.00],
                ['csi_invoice_number' => 'RT0001', 'csi_asset_id' => 503, 'serial' => 'N/A', 'period_rent' => 12.00],
            ],
        ])->assertOk()->assertStatusMessageIs('success');

        // A second sync updates in place rather than duplicating.
        $actor->postJson(route('api.csi.snapshot'), [
            'entity' => 'invoice_assets',
            'items' => [array_merge($line, ['period_rent' => 95.50])],
        ])->assertOk();

        $this->assertEquals(3, CsiInvoiceAsset::count());
        $row = CsiInvoiceAsset::where('serial', 'SN-A')->first();
        $this->assertEquals(95.50, (float) $row->period_rent);
        $this->assertEquals(5.00, (float) $row->tax_gst);
        $this->assertEquals(7.00, (float) $row->tax_pst);
        $this->assertEquals('2026-07-01', $row->period_start_date->format('Y-m-d'));
        $this->assertNotNull($row->last_seen_at);

        CsiInvoice::create(['csi_invoice_number' => 'RT0001']);
        $this->assertEquals(3, CsiInvoice::first()->assetLines()->count());
    }

    public function test_activated_schedule_purges_its_inprocess_rows()
    {
        CsiInprocessAsset::create(['serial' => 'SN-OLD', 'schedule_name' => '100000-007']);
        CsiInprocessAsset::create(['serial' => 'SN-WAIT', 'schedule_name' => '100000-009']);

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.csi.snapshot'), [
                'entity' => 'schedules',
                'items' => [
                    ['schedule_name' => '100000-007', 'lease_number' => '100000', 'term_start_date' => now()->subMonth()->toDateString()],
                    // Published but not yet commenced: its in-process rows stay.
                    ['schedule_name' => '100000-009', 'lease_number' => '100000', 'term_start_date' => now()->addMonth()->toDateString()],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('payload.purged_inprocess', 1);

        $this->assertEquals(['SN-WAIT'], CsiInprocessAsset::pluck('serial')->all());
    }

    public function test_inprocess_rows_for_an_active_schedule_are_not_kept()
    {
        CsiSchedule::create(['schedule_name' => '100000-007', 'term_start_date' => now()->subMonth()->toDateString()]);

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.csi.snapshot'), [
                'entity' => 'inprocess',
                'items' => [
                    ['serial' => 'SN-OLD', 'schedule_name' => '100000-007'],
                    ['serial' => 'SN-NEW', 'schedule_name' => '100000-009'],
                ],
            ])
            ->assertOk();

        $this->assertEquals(['SN-NEW'], CsiInprocessAsset::pluck('serial')->all());
    }

    public function test_rejects_unknown_entity()
    {
        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.csi.snapshot'), ['entity' => 'bogus', 'items' => []])
            ->assertOk()
            ->assertStatusMessageIs('error');
    }
}
