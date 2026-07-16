<?php

namespace App\Services\UserAgreements;

use App\Models\Asset;
use App\Models\Contract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One-time migration: walk every asset that carries lease info in the
 * legacy Snipe-IT custom fields (Lease Contract Name / Lease Contract
 * ID / Lease End Date) and tie that asset to the matching real
 * Contract entity via the `contract_asset` bridge.
 *
 * The Reconciler reads lease state from the bridge, so once this
 * runs the "L002916 has no contract" problem disappears and the
 * Reconciler does the right thing without learning about custom
 * fields. Idempotent — re-runs are safe.
 *
 * Contracts the migration creates are stamped `source='snipe'` —
 * that's the identifier for "this contract is owned by the Snipe-IT
 * app itself, not by TDX/CSI". The contracts upsert endpoint (#130)
 * refuses to overwrite any contract whose source is not 'tdx', so
 * these stay safe regardless of what the Azure Functions push at
 * /api/v1/contracts/upsert.
 *
 * Used by:
 *   - `snipeit:link-assets-to-contracts` artisan command
 *   - `POST /api/v1/assets/link-to-contracts` API endpoint
 */
class AssetContractLinker
{
    /**
     * @return AssetContractLinkReport
     */
    public function run(bool $dryRun = false, ?int $assetId = null): AssetContractLinkReport
    {
        $report = new AssetContractLinkReport();

        $contractNameCol = $this->columnFor('contract_name_field_name');
        if (! $contractNameCol) {
            Log::warning('asset-contract linker: contract-name custom field not found, nothing to do');
            return $report;
        }

        $endDateCol = $this->columnFor('lease_end_date_field_name');
        $namePattern = (string) config('forms.asset_lease_migration.contract_name_pattern', '');

        $query = Asset::query()->whereNotNull($contractNameCol);
        if ($namePattern !== '') {
            $query->where($contractNameCol, 'like', $namePattern);
        }
        if ($assetId) {
            $query->where('id', $assetId);
        }

        foreach ($query->cursor() as $asset) {
            $name = trim((string) $asset->getAttribute($contractNameCol));
            if ($name === '') {
                continue;
            }

            $report->assetsScanned++;

            $endDate = $endDateCol ? $asset->getAttribute($endDateCol) : null;
            $endDateNormalised = $this->normaliseDate($endDate);

            $contract = $this->findOrCreateContract($name, $endDateNormalised, $dryRun, $report);
            if (! $contract) {
                $report->assetsSkipped++;
                continue;
            }

            if ($this->bridgeExists($contract->id, $asset->id)) {
                $report->bridgesAlreadyPresent++;
                continue;
            }

            if ($dryRun) {
                $report->bridgesPlanned++;
                continue;
            }

            $this->createBridge($contract->id, $asset->id);
            $report->bridgesCreated++;
            $report->createdPairs[] = ['contract_id' => $contract->id, 'asset_id' => $asset->id];
        }

        return $report;
    }

    private function findOrCreateContract(string $name, ?string $endDate, bool $dryRun, AssetContractLinkReport $report): ?Contract
    {
        $existing = Contract::query()->where('name', $name)->first();
        if ($existing) {
            // Keep the existing end_date if one is already set — the
            // contract may be richer than what the custom field knew.
            return $existing;
        }

        if ($dryRun) {
            $report->contractsPlanned++;
            return null;
        }

        $contract = Contract::create([
            'name'            => $name,
            'contract_number' => $name,
            'source'          => 'snipe',
            'is_active'       => true,
            'end_date'        => $endDate,
        ]);
        $report->contractsCreated++;

        return $contract;
    }

    private function bridgeExists(int $contractId, int $assetId): bool
    {
        return DB::table('contract_asset')
            ->where('contract_id', $contractId)
            ->where('asset_id', $assetId)
            ->exists();
    }

    private function createBridge(int $contractId, int $assetId): void
    {
        DB::table('contract_asset')->insert([
            'contract_id' => $contractId,
            'asset_id'    => $assetId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function columnFor(string $key): ?string
    {
        $name = (string) config("forms.asset_lease_migration.$key", '');
        if ($name === '') {
            return null;
        }
        $native = Asset::nativeColumnForCustomName($name);

        return $native && Schema::hasColumn('assets', $native) ? $native : null;
    }

    private function normaliseDate(mixed $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
