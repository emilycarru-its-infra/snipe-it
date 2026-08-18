<?php

namespace Tests\Feature\Contracts;

use App\Models\Contract;
use App\Models\Supplier;
use App\Services\Contracts\LegacyContractReconciler;
use Tests\TestCase;

/**
 * Retiring the `LIC-*` rows that `licenses:migrate-saas-to-contracts` left
 * behind, wherever TDX now carries the same product.
 */
class LegacyContractReconcilerTest extends TestCase
{
    private function legacy(array $overrides = []): Contract
    {
        return Contract::factory()->create(array_merge([
            'contract_number' => 'LIC-6129',
            'name' => 'Render Suite',
            'product' => null,
            'source' => 'manual',
            'tdx_id' => null,
            'is_active' => true,
            'end_date' => now()->addDays(30)->toDateString(),
        ], $overrides));
    }

    private function tdx(array $overrides = []): Contract
    {
        return Contract::factory()->create(array_merge([
            'contract_number' => 'ISS FY26-27 (Render Suite)',
            'name' => 'Render Suite',
            'product' => 'Render Suite',
            'source' => 'tdx',
            'tdx_id' => 4683,
            'is_active' => true,
            'end_date' => now()->addYear()->toDateString(),
        ], $overrides));
    }

    public function test_preview_reports_without_writing(): void
    {
        $legacy = $this->legacy();
        $this->tdx();

        $report = (new LegacyContractReconciler)->run(false);

        $this->assertSame(1, $report->scanned);
        $this->assertSame(1, $report->matched);
        $this->assertSame(0, $report->written);
        $this->assertTrue($legacy->fresh()->is_active);
    }

    public function test_write_files_the_legacy_row_under_the_tdx_parent_and_deactivates_it(): void
    {
        $parent = Contract::factory()->create([
            'name' => 'Northwind Systems — Render Suite',
            'is_synthesized' => true,
            'source' => 'synthesized',
        ]);
        $supplier = Supplier::factory()->create();
        $legacy = $this->legacy(['supplier_id' => null]);
        $this->tdx(['parent_contract_id' => $parent->id, 'supplier_id' => $supplier->id]);

        $report = (new LegacyContractReconciler)->run(true);

        $this->assertSame(1, $report->written);
        $legacy = $legacy->fresh();
        $this->assertFalse($legacy->is_active);
        $this->assertSame($parent->id, $legacy->parent_contract_id);
        $this->assertSame('Render Suite', $legacy->product);
        $this->assertSame($supplier->id, $legacy->supplier_id);
        $this->assertStringContainsString('Superseded by ISS FY26-27 (Render Suite)', $legacy->notes);
    }

    public function test_leaves_a_legacy_row_with_no_tdx_counterpart_alone(): void
    {
        $legacy = $this->legacy(['name' => 'Some Retired Tool']);
        $this->tdx();

        $report = (new LegacyContractReconciler)->run(true);

        $this->assertSame(0, $report->matched);
        $this->assertCount(1, $report->unmatched());
        $this->assertTrue($legacy->fresh()->is_active);
    }

    public function test_a_different_supplier_vetoes_the_match(): void
    {
        $ours = Supplier::factory()->create();
        $theirs = Supplier::factory()->create();
        $legacy = $this->legacy(['supplier_id' => $ours->id]);
        $this->tdx(['supplier_id' => $theirs->id]);

        $report = (new LegacyContractReconciler)->run(true);

        $this->assertSame(0, $report->matched);
        $this->assertTrue($legacy->fresh()->is_active);
    }

    public function test_never_touches_tdx_owned_or_already_retired_rows(): void
    {
        $tdx = $this->tdx();
        $retired = $this->legacy(['contract_number' => 'LIC-180', 'is_active' => false]);

        $report = (new LegacyContractReconciler)->run(true);

        $this->assertSame(0, $report->scanned);
        $this->assertTrue($tdx->fresh()->is_active);
        $this->assertFalse($retired->fresh()->is_active);
    }

    public function test_is_idempotent(): void
    {
        $this->legacy();
        $this->tdx();

        $first = (new LegacyContractReconciler)->run(true);
        $second = (new LegacyContractReconciler)->run(true);

        $this->assertSame(1, $first->written);
        $this->assertSame(0, $second->scanned);
        $this->assertSame(0, $second->written);
    }
}
