<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\CustomField;
use App\Models\Supplier;
use App\Services\Leasing\LessorBackfillService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LessorBackfillTest extends TestCase
{
    private string $contractCol;
    private string $ownershipCol;

    protected function setUp(): void
    {
        parent::setUp();

        $contract = CustomField::factory()->create(['name' => 'Lease Contract ID', 'format' => 'ANY']);
        $ownership = CustomField::factory()->create(['name' => 'Ownership Type', 'format' => 'ANY']);

        $this->contractCol = $contract->db_column;
        $this->ownershipCol = $ownership->db_column;
    }

    private function asset(?string $contractId, string $ownership = 'Lease', ?int $lessorId = null): Asset
    {
        $asset = Asset::factory()->create(['lessor_id' => $lessorId]);
        DB::table('assets')->where('id', $asset->id)->update([
            $this->contractCol  => $contractId,
            $this->ownershipCol => $ownership,
        ]);

        return $asset->fresh();
    }

    public function test_preview_reports_without_writing(): void
    {
        $csi = $this->asset('301452-003');
        $cca = $this->asset('ECI-99');

        $report = app(LessorBackfillService::class)->run(false);

        $this->assertSame(2, $report->resolved);
        $this->assertSame(0, $report->written);
        $this->assertNull($csi->fresh()->lessor_id);
        $this->assertNull($cca->fresh()->lessor_id);
    }

    public function test_write_sets_lessor_from_contract_prefix(): void
    {
        $csi = $this->asset('301452-003');
        $eci = $this->asset('ECI-99');
        $cca4130 = $this->asset('4130-12');

        $report = app(LessorBackfillService::class)->run(true);

        $csiSupplier = Supplier::where('name', 'CSI Leasing')->firstOrFail();
        $ccaSupplier = Supplier::where('name', 'CCA Financial')->firstOrFail();

        $this->assertSame(3, $report->written);
        $this->assertSame($csiSupplier->id, $csi->fresh()->lessor_id);
        $this->assertSame($ccaSupplier->id, $eci->fresh()->lessor_id);
        $this->assertSame($ccaSupplier->id, $cca4130->fresh()->lessor_id);
    }

    public function test_unrecognised_contract_id_is_reported_unresolved(): void
    {
        $this->asset('SOMETHING-ELSE');
        $this->asset(null); // leased but no contract id

        $report = app(LessorBackfillService::class)->run(true);

        $this->assertSame(0, $report->resolved);
        $this->assertCount(2, $report->unresolved);
    }

    public function test_existing_lessor_is_never_overwritten(): void
    {
        $existing = Supplier::factory()->create(['name' => 'Manually Set Lessor']);
        $asset = $this->asset('301452-003', 'Lease', $existing->id);

        $report = app(LessorBackfillService::class)->run(true);

        $this->assertSame(0, $report->scanned);
        $this->assertSame($existing->id, $asset->fresh()->lessor_id);
    }
}
