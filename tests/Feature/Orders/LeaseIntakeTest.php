<?php

namespace Tests\Feature\Orders;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\Contract;
use App\Models\ContractAttribute;
use App\Models\ContractSerial;
use App\Models\LeaseSchedule;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Leasing\LeaseDocumentParser;
use App\Services\Leasing\ScheduleIntake;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The intake side of lease documents: a signed schedule agreement opens the
 * schedule, a certificate of acceptance finalizes it into a per-schedule
 * contract under the lease-number umbrella, and an Exhibit A draft layers
 * the financed cost numbers on top.
 */
class LeaseIntakeTest extends TestCase
{
    private function intake(): ScheduleIntake
    {
        return app(ScheduleIntake::class);
    }

    private function acceptance(array $overrides = []): array
    {
        return array_merge([
            'type' => LeaseDocumentParser::TYPE_CERTIFICATE_OF_ACCEPTANCE,
            'lessor' => 'Example Leasing Canada Ltd.',
            'lease_number' => '900123',
            'schedule_ref' => '900123-003',
            'dated_as_of' => '2026-04-09',
            'term_start' => '2026-07-01',
            'term_end' => '2030-06-30',
            'term_months' => 48,
            'yearly_rental' => 1234.56,
            'stip_loss_value' => 5678.9,
            'equipment_location' => '123 EXAMPLE ST',
            'lines' => [
                ['qty' => 1, 'description' => 'LAPTOP 14" X1', 'serial' => 'TESTSER001', 'condition' => 'New', 'yearly_rental' => 906.87, 'commencement' => '2026-04-27'],
                ['qty' => 1, 'description' => 'TABLET 13" X2', 'serial' => 'TESTSER002', 'condition' => 'New', 'yearly_rental' => 327.69, 'commencement' => '2026-06-18'],
            ],
        ], $overrides);
    }

    private function agreement(array $overrides = []): array
    {
        return array_merge([
            'type' => LeaseDocumentParser::TYPE_SCHEDULE_AGREEMENT,
            'lessor' => 'Example Leasing Canada Ltd.',
            'lease_number' => '900123',
            'schedule_ref' => '900123-009',
            'dated_as_of' => '2026-07-22',
            'term_start' => '2026-10-01',
            'term_end' => '2030-09-30',
            'term_months' => 48,
            'cost_cap' => 250000.0,
            'purchase_option' => false,
            'lease_type' => 'Lease to Return',
        ], $overrides);
    }

    private function document(string $name = 'signed.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 24, 'application/pdf');
    }

    public function test_an_agreement_opens_the_schedule_as_signed()
    {
        $this->actingAs(User::factory()->superuser()->create());

        $result = $this->intake()->apply($this->agreement(), $this->document());

        $this->assertSame('opened', $result['action']);
        $schedule = LeaseSchedule::where('schedule_ref', '900123-009')->first();
        $this->assertNotNull($schedule);
        $this->assertSame('signed', $schedule->lifecycle_stage);
        $this->assertSame('Lease to Return', $schedule->lease_type);
        $this->assertEquals(250000.0, (float) $schedule->expected_acquisition_cost);
        $this->assertTrue(
            Actionlog::where('item_type', LeaseSchedule::class)
                ->where('item_id', $schedule->id)
                ->where('action_type', 'uploaded')->exists()
        );
    }

    public function test_finalizing_creates_the_contract_under_the_umbrella()
    {
        $this->actingAs(User::factory()->superuser()->create());
        Supplier::create(['name' => 'Example Leasing']);

        $result = $this->intake()->apply($this->acceptance(), $this->document());

        $this->assertSame('finalized', $result['action']);

        $contract = $result['contract'];
        $this->assertSame('Devices Leases FY26-27 #1', $contract->name);
        $this->assertSame('900123-003', $contract->schedule_number);
        $this->assertSame('2026-07-01', $contract->start_date->toDateString());
        $this->assertSame('2030-06-30', $contract->end_date->toDateString());
        $this->assertTrue($contract->is_active);

        // The lessor resolved to the existing supplier instead of creating
        // a near-duplicate from the document's longer legal name.
        $this->assertSame('Example Leasing', $contract->supplier->name);

        $umbrella = $contract->parent;
        $this->assertNotNull($umbrella);
        $this->assertSame('900123', $umbrella->contract_number);
        $this->assertTrue($umbrella->is_synthesized);

        $this->assertSame(2, ContractSerial::where('contract_id', $contract->id)->count());
        $this->assertSame('1234.56', ContractAttribute::where('contract_id', $contract->id)->where('name', 'yearly_rental')->value('value'));

        $schedule = LeaseSchedule::where('schedule_ref', '900123-003')->first();
        $this->assertSame('active', $schedule->lifecycle_stage);
        $this->assertSame($contract->id, $schedule->contract_id);

        $this->assertTrue(
            Actionlog::where('item_type', Contract::class)
                ->where('item_id', $contract->id)
                ->where('action_type', 'uploaded')->exists()
        );
    }

    public function test_finalizing_requires_the_signed_document()
    {
        $this->actingAs(User::factory()->superuser()->create());

        $this->expectException(\RuntimeException::class);

        $this->intake()->apply($this->acceptance(), null);
    }

    public function test_series_numbering_continues_within_the_fiscal_year()
    {
        $this->actingAs(User::factory()->superuser()->create());

        Contract::factory()->create([
            'name' => 'Devices Leases FY26-27 #7',
            'contract_number' => 'Devices Leases FY26-27 #7',
        ]);

        $result = $this->intake()->apply($this->acceptance(), $this->document());

        $this->assertSame('Devices Leases FY26-27 #8', $result['contract']->name);
    }

    public function test_refinalizing_updates_rather_than_duplicates()
    {
        $this->actingAs(User::factory()->superuser()->create());

        $first = $this->intake()->apply($this->acceptance(), $this->document());
        $second = $this->intake()->apply(
            $this->acceptance(['yearly_rental' => 2000.0]),
            $this->document('signed-v2.pdf')
        );

        $this->assertSame($first['contract']->id, $second['contract']->id);
        $this->assertSame(1, Contract::where('schedule_number', '900123-003')->count());
        $this->assertSame('2000', ContractAttribute::where('contract_id', $first['contract']->id)->where('name', 'yearly_rental')->value('value'));
    }

    public function test_an_exhibit_draft_reconciles_the_financed_cost()
    {
        $this->actingAs(User::factory()->superuser()->create());

        $contract = $this->intake()->apply($this->acceptance(), $this->document())['contract'];

        $result = $this->intake()->apply([
            'type' => LeaseDocumentParser::TYPE_EXHIBIT_A_DRAFT,
            'lease_number' => '900123',
            'schedule_ref' => '900123-003',
            'totals' => ['total_rent' => 1234.56, 'equipment_cost' => 29893.98, 'soft_cost' => 2417.82, 'total_cost' => 32311.8],
            'lines' => [
                ['serial' => 'TESTSER001', 'description' => 'LAPTOP 14" X1', 'yearly_rental' => 906.87, 'equipment_cost' => 3061.22, 'invoice_numbers' => 'INV001', 'total_cost' => 3457.64],
            ],
        ], $this->document('exhibit.xlsx'));

        $this->assertSame('reconciled', $result['action']);
        $this->assertEquals(32311.8, (float) $contract->fresh()->total_cost);
        $this->assertSame('29893.98', ContractAttribute::where('contract_id', $contract->id)->where('name', 'equipment_cost')->value('value'));

        $serial = ContractSerial::where('contract_id', $contract->id)->where('serial', 'TESTSER001')->first();
        $this->assertSame('exhibit-a-draft', $serial->source);
        $this->assertStringContainsString('INV001', $serial->notes);
    }

    public function test_warnings_surface_unknown_serials_and_missing_mirror()
    {
        $this->actingAs(User::factory()->superuser()->create());

        Asset::factory()->create(['serial' => 'TESTSER001']);

        $result = $this->intake()->apply($this->acceptance(), $this->document());

        $joined = implode(' ', $result['warnings']);
        $this->assertStringContainsString('TESTSER002', $joined);
        $this->assertStringNotContainsString('TESTSER001,', $joined);
        $this->assertStringContainsString('mirror', $joined);
    }
}
