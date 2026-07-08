<?php

namespace App\Services\Leasing;

use App\Models\Asset;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Populates the native asset `lessor_id` (a Supplier record in the lessor role)
 * for leased devices, deriving the lessor from the "Lease Contract ID" custom
 * field prefix — the same mapping the 2026_06_19 migration seeded once:
 *
 *   301452-*        -> CSI Leasing
 *   ECI* / 4130*    -> CCA Financial
 *
 * This is the durable, re-runnable version of that one-time backfill: it only
 * ever sets lessor_id where it's currently null (never overwrites a manual
 * assignment), so it's safe to schedule. Assets that look leased (an Ownership
 * Type of "Lease", or any Lease Contract ID) but whose contract ID doesn't
 * match a known prefix are reported as unresolved for manual assignment.
 */
class LessorBackfillService
{
    /** Resolve a custom field's db_column by display name, or null if absent. */
    private function column(string $name): ?string
    {
        return DB::table('custom_fields')->where('name', $name)->value('db_column');
    }

    /** Get-or-create a lessor Supplier by name, returning its id. */
    private function ensureSupplier(string $name): int
    {
        return (int) (Supplier::firstOrCreate(['name' => $name])->id);
    }

    /**
     * Map a Lease Contract ID to a lessor supplier id, or null when the prefix
     * isn't recognised.
     */
    private function resolve(string $contractId, int $csiId, int $ccaId): ?int
    {
        $id = trim($contractId);
        if ($id === '') {
            return null;
        }
        if (str_starts_with($id, '301452')) {
            return $csiId;
        }
        if (str_starts_with(strtoupper($id), 'ECI') || str_starts_with($id, '4130')) {
            return $ccaId;
        }

        return null;
    }

    /**
     * Sweep leased assets missing a lessor and set it where derivable.
     *
     * @param  bool  $write  When false (default) nothing is written — the report
     *                       still reflects what *would* be set, so callers can
     *                       preview the change set first.
     */
    public function run(bool $write = false): LessorBackfillReport
    {
        $report = new LessorBackfillReport;

        $contractCol = $this->column('Lease Contract ID');
        $ownershipCol = $this->column('Ownership Type');

        // Nothing to key off — no lease custom fields in this environment.
        if (! $contractCol && ! $ownershipCol) {
            return $report;
        }

        $csiId = $this->ensureSupplier('CSI Leasing');
        $ccaId = $this->ensureSupplier('CCA Financial');

        $query = Asset::query()->whereNull('lessor_id')
            ->where(function ($q) use ($contractCol, $ownershipCol) {
                if ($contractCol) {
                    $q->orWhere(fn ($qq) => $qq->whereNotNull($contractCol)->where($contractCol, '!=', ''));
                }
                if ($ownershipCol) {
                    $q->orWhere($ownershipCol, 'like', '%lease%');
                }
            });

        foreach ($query->cursor() as $asset) {
            $report->scanned++;

            $contractId = $contractCol ? (string) $asset->{$contractCol} : '';
            $lessorId = $this->resolve($contractId, $csiId, $ccaId);

            if ($lessorId === null) {
                $report->unresolved[] = [
                    'id'          => $asset->id,
                    'asset_tag'   => $asset->asset_tag,
                    'contract_id' => trim($contractId),
                ];
                continue;
            }

            $report->resolved++;

            if ($write) {
                // Direct update: set the FK without churning updated_at or firing
                // asset observers/activity for a silent data-quality backfill.
                DB::table('assets')->where('id', $asset->id)->update(['lessor_id' => $lessorId]);
                $report->written++;
            }
        }

        return $report;
    }
}
