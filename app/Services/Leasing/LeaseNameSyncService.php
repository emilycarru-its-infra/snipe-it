<?php

namespace App\Services\Leasing;

use App\Models\Asset;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the lease display name from the contracts register onto every leased
 * asset, matching `contracts.schedule_number` to the asset's Lease Contract ID.
 *
 * The register is the naming source of truth: a contract is named for the
 * fiscal year it *commences* in, zero-padded and restarting at #01 each year
 * (`4130-ECI20221001` -> "Devices Leases FY22-23 #03"). The asset-side value is
 * only a mirror, but nothing derived it — it was written by one-off API sweeps
 * and by import — so it drifted completely: at the time this was written every
 * leased asset disagreed with the register, 1,051 of them carrying the
 * superseded lease-*end*-FY form ("Devices Leases FY27-28 #1" for that same
 * contract) and 118 carrying no name at all.
 *
 * That is why this exists as a re-runnable command rather than another one-off:
 * renaming a contract now propagates on the next run instead of silently
 * leaving the fleet behind.
 *
 * Assets whose Lease Contract ID matches no register row are reported rather
 * than blanked — an unknown schedule is a finding, not a reason to destroy the
 * name someone entered by hand.
 */
class LeaseNameSyncService
{
    /**
     * @return array{scanned:int, matched:int, written:int, unmatched:array<string,int>, changes:array<int,array{contract:string, from:string, to:string, assets:int}>}
     */
    public function run(bool $write): array
    {
        // schedule_number -> canonical name, for every live lease contract that
        // actually carries a schedule. Rows with a null schedule_number can't be
        // matched to an asset and are skipped rather than guessed at.
        $register = Contract::query()
            ->whereNotNull('schedule_number')
            ->where('schedule_number', '!=', '')
            ->pluck('name', 'schedule_number');

        $scanned = 0;
        $matched = 0;
        $written = 0;
        $unmatched = [];
        $changes = [];

        Asset::query()
            ->whereNotNull('lease_contract_id')
            ->where('lease_contract_id', '!=', '')
            ->select('id', 'lease_contract_id', 'lease_contract_name')
            ->chunkById(500, function ($assets) use ($register, $write, &$scanned, &$matched, &$written, &$unmatched, &$changes) {
                foreach ($assets as $asset) {
                    $scanned++;
                    $scheduleNumber = trim((string) $asset->lease_contract_id);
                    $canonical = $register->get($scheduleNumber);

                    if ($canonical === null) {
                        $unmatched[$scheduleNumber] = ($unmatched[$scheduleNumber] ?? 0) + 1;

                        continue;
                    }

                    $matched++;
                    $current = (string) $asset->lease_contract_name;
                    if ($current === $canonical) {
                        continue;
                    }

                    $key = $scheduleNumber.'|'.$current;
                    if (! isset($changes[$key])) {
                        $changes[$key] = [
                            'contract' => $scheduleNumber,
                            'from' => $current === '' ? '(blank)' : $current,
                            'to' => $canonical,
                            'assets' => 0,
                        ];
                    }
                    $changes[$key]['assets']++;
                    $written++;

                    if ($write) {
                        // Update through the query builder: the display name is
                        // derived data, so this must not fire asset observers,
                        // touch timestamps, or land in the asset's history.
                        DB::table('assets')->where('id', $asset->id)
                            ->update(['lease_contract_name' => $canonical]);
                    }
                }
            });

        ksort($unmatched);

        return [
            'scanned' => $scanned,
            'matched' => $matched,
            'written' => $written,
            'unmatched' => $unmatched,
            'changes' => array_values($changes),
        ];
    }
}
