<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\CustomField;
use App\Models\Supplier;
use App\Services\Leasing\LessorBackfillService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            $this->contractCol => $contractId,
            $this->ownershipCol => $ownership,
        ]);

        return $asset->fresh();
    }

    /** @return array{0: Supplier, 1: Supplier} */
    private function lessors(): array
    {
        return [
            Supplier::factory()->create(['name' => 'Lessor One', 'contract_prefixes' => '700100-']),
            Supplier::factory()->create(['name' => 'Lessor Two', 'contract_prefixes' => '700200-,QQ']),
        ];
    }

    public function test_preview_reports_without_writing(): void
    {
        $this->lessors();
        $one = $this->asset('700100-003');
        $two = $this->asset('QQ-99');

        $report = app(LessorBackfillService::class)->run(false);

        $this->assertSame(2, $report->resolved);
        $this->assertSame(0, $report->written);
        $this->assertNull($one->fresh()->lessor_id);
        $this->assertNull($two->fresh()->lessor_id);
    }

    public function test_write_sets_lessor_from_declared_contract_prefix(): void
    {
        [$lessorOne, $lessorTwo] = $this->lessors();
        $one = $this->asset('700100-003');
        $two = $this->asset('QQ-99');
        $twoAgain = $this->asset('700200-12');

        $report = app(LessorBackfillService::class)->run(true);

        $this->assertSame(3, $report->written);
        $this->assertSame($lessorOne->id, $one->fresh()->lessor_id);
        $this->assertSame($lessorTwo->id, $two->fresh()->lessor_id);
        $this->assertSame($lessorTwo->id, $twoAgain->fresh()->lessor_id);
    }

    public function test_unrecognised_contract_id_is_reported_unresolved(): void
    {
        $this->lessors();
        $this->asset('SOMETHING-ELSE');
        $this->asset(null); // leased but no contract id

        $report = app(LessorBackfillService::class)->run(true);

        $this->assertSame(0, $report->resolved);
        $this->assertCount(2, $report->unresolved);
    }

    public function test_with_no_prefixes_declared_it_logs_and_writes_nothing(): void
    {
        Log::spy();
        $before = Supplier::count();
        $asset = $this->asset('700100-003');
        $created = Supplier::count() - $before;

        $report = app(LessorBackfillService::class)->run(true);

        $this->assertSame(0, $report->written);
        $this->assertNull($asset->fresh()->lessor_id);
        // Nothing beyond the asset's own fixtures is created to stand in for a lessor.
        $this->assertSame($before + $created, Supplier::count());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_existing_lessor_is_never_overwritten(): void
    {
        $this->lessors();
        $existing = Supplier::factory()->create(['name' => 'Manually Set Lessor']);
        $asset = $this->asset('700100-003', 'Lease', $existing->id);

        $report = app(LessorBackfillService::class)->run(true);

        $this->assertSame(0, $report->scanned);
        $this->assertSame($existing->id, $asset->fresh()->lessor_id);
    }
}
