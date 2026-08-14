<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The buyouts that ran through email in 2026, reconstructed onto the tracker
 * so the lane opens with history rather than an empty table.
 *
 * Every figure here is transcribed from the thread it was quoted in, and
 * nothing is inferred: where a split between buyer and ECU was never stated,
 * the buyer carries the whole total, which is what the tracker defaults to.
 * Where a case was opened by person and never pinned to a device — HR asks
 * "what would X's laptop cost" long before anyone looks up a tag — the row
 * carries the buyer and no asset, which is the true state of it.
 *
 * Idempotent: matched on asset tag (or buyer, for the unpinned case) so a
 * re-run updates rather than duplicates. Anything it cannot resolve on this
 * database — a dev or test box without these devices — is skipped silently.
 */
return new class extends Migration
{
    /**
     * Reconstructed cases, oldest first. `tag` resolves the asset; `serial`
     * is the fallback where only a serial was ever quoted; `buyer` resolves
     * the person. Quotes are listed oldest-first, so the last one wins.
     */
    private const CASES = [
        [
            // Leslie Bishko, retiring September. The thread never named the
            // device, but the checkin that closed her offboarding on
            // 2026-08-13 did: L003565, the MacBook Pro she had held since
            // 2024-05-27. Same shape as the Bussigel case below — the buyer
            // pays the buyout price by payroll deduction and ECU absorbs the
            // remaining rent, because the lease runs to 2027-09-01.
            'tag' => 'L003565',
            'serial' => 'MV64N0YJ4L',
            'buyer' => 'lbishko@ecuad.ca',
            'status' => 'approved',
            'requested_at' => '2026-04-24 16:38:00',
            'approved_at' => '2026-08-13 13:31:00',
            'buyer_amount' => 899.99,
            'ecu_amount' => 872.01,
            'quotes' => [
                ['amount' => 899.99, 'rent' => 872.01, 'date' => '2026-04-24', 'notes' => 'Quoted mid-lease-cycle by Device Services; no lessor quote on file.'],
            ],
            'notes' => 'Opened by HR for a September retirement. Buyout confirmed at offboarding on 2026-08-13. Buyer pays $899.99 by payroll deduction; ECU absorbs the $872.01 remaining rent on a lease running to 2027-09-01. Payment never confirmed.',
        ],
        [
            // John Li, resigned 2026-05-01. HR asked the balance, then chose
            // not to offer a buyout at all.
            'tag' => null,
            'serial' => 'L7NNVQWR7R',
            'buyer' => null,
            'status' => 'declined',
            'requested_at' => '2026-04-27 13:59:00',
            'declined_at' => '2026-04-28 15:09:00',
            'decline_reason' => 'HR chose not to offer a buyout on a resignation.',
            'quotes' => [],
            'notes' => 'MacBook Pro 14-inch M4 2024, roughly six months old; approximate value CAD 2,269.00 given as an estimate, never a lessor quote.',
        ],
        [
            // Diyan Achjadi. Quote 2026-07-15, approval 2026-07-24, actioned
            // with the lessor 2026-07-27, invoice 2026-08-05. Payment route
            // still being settled — payroll deduction was the expectation.
            'tag' => 'L003344',
            'serial' => 'C32Q5N2KHH',
            'buyer' => 'dachjadi@ecuad.ca',
            'status' => 'invoiced',
            'requested_at' => '2026-07-08 17:56:00',
            'approved_at' => '2026-07-24 22:50:00',
            'invoice_date' => '2026-08-05',
            'quotes' => [
                ['amount' => 675.00, 'rent' => 642.25, 'date' => '2026-07-15', 'notes' => 'CCA Financial.'],
            ],
            'notes' => 'Buyout actioned with CCA on 2026-07-27; invoice received 2026-08-05, remittance to arcnd@ccafinancial.com. Payment route not settled — payroll deduction expected. No buyer/ECU split was stated.',
        ],
        [
            // Christine Stewart, retiring end of August. CCA priced it twice
            // on the same day, four hours apart; the second is live.
            'tag' => 'L003290',
            'serial' => 'VD9FT974P0',
            'buyer' => 'cstewart@ecuad.ca',
            'status' => 'quoted',
            'requested_at' => '2026-07-16 15:09:00',
            'quotes' => [
                ['amount' => 975.00, 'rent' => 842.40, 'date' => '2026-07-21', 'notes' => 'CCA Financial, original quote.'],
                ['amount' => 790.00, 'rent' => 842.40, 'date' => '2026-07-21', 'notes' => 'CCA Financial, revised down after their sales rep re-valued the asset. Supersedes the original.'],
            ],
            'notes' => 'HR connecting with Christine on the revised figure. No decision recorded.',
        ],
        [
            // Gonzalo Reyes Rodriguez. Request sent to CSI Leasing 2026-08-06,
            // chased twice, rushed by CSI on 2026-08-13, still unpriced.
            'tag' => 'A004789',
            'serial' => 'F7JHYQND9L',
            'buyer' => 'greyesrodriguez@ecuad.ca',
            'status' => 'requested',
            'requested_at' => '2026-08-06 16:54:00',
            'quotes' => [],
            'notes' => 'CSI Leasing, contract 301452-003. Chased 2026-08-13; CSI put a rush on it. Tight timeline on the employee departure.',
        ],
        [
            // Peter Bussigel. The one case where the split was stated: because
            // the contract ends 2027-10-01, the buyer pays the buyout price
            // and ECU absorbs the remaining rent.
            'tag' => 'L003339',
            'serial' => 'CFGQ16TNFV',
            'buyer' => 'pbussigel@ecuad.ca',
            'status' => 'quoted',
            'requested_at' => '2026-08-12 22:24:00',
            'buyer_amount' => 675.00,
            'ecu_amount' => 642.25,
            'quotes' => [
                ['amount' => 675.00, 'rent' => 642.25, 'date' => '2026-08-13', 'notes' => 'CCA Financial, contract 4130-ECI20221001.'],
            ],
            'notes' => 'Buyer offered the buyout price only ($675.00); ECU absorbs the remaining rent because the contract runs to 2027-10-01.',
        ],
    ];

    public function up(): void
    {
        foreach (self::CASES as $case) {
            $asset = $this->resolveAsset($case);
            $buyerId = $this->resolveBuyer($case['buyer'] ?? null);

            // Without either an asset or a buyer there is nothing to hang the
            // record on — that only happens on a database these devices and
            // people do not exist in.
            if (! $asset && ! $buyerId) {
                continue;
            }

            $quotes = $case['quotes'];
            $live = $quotes ? end($quotes) : null;
            $total = $live ? (float) $live['amount'] + (float) $live['rent'] : null;

            // Matched on the asset where there is one, otherwise on the buyer —
            // `where(['asset_id' => null])` would compare against NULL and
            // never match, so the unpinned case needs whereNull.
            $existing = DB::table('asset_buyouts')
                ->when($asset, fn ($q) => $q->where('asset_id', $asset->id))
                ->unless($asset, fn ($q) => $q->whereNull('asset_id')->where('buyer_id', $buyerId))
                ->first();

            $values = [
                'asset_id' => $asset?->id,
                'lessor_id' => $asset?->lessor_id,
                'buyer_id' => $buyerId,
                'status' => $case['status'],
                'requested_at' => $case['requested_at'],
                'quote_amount' => $live ? $live['amount'] : null,
                'remaining_rent' => $live ? $live['rent'] : null,
                'quote_total' => $total,
                'quoted_at' => $live ? $live['date'] : null,
                'buyer_amount' => $case['buyer_amount'] ?? $total,
                'ecu_amount' => $case['ecu_amount'] ?? ($total !== null ? 0 : null),
                'approved_at' => $case['approved_at'] ?? null,
                'declined_at' => $case['declined_at'] ?? null,
                'decline_reason' => $case['decline_reason'] ?? null,
                'invoice_date' => $case['invoice_date'] ?? null,
                'notes' => $case['notes'],
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('asset_buyouts')->where('id', $existing->id)->update($values);
                $buyoutId = $existing->id;
                DB::table('asset_buyout_quotes')->where('asset_buyout_id', $buyoutId)->delete();
            } else {
                $buyoutId = DB::table('asset_buyouts')->insertGetId(
                    $values + ['created_at' => $case['requested_at']]
                );
            }

            foreach ($quotes as $quote) {
                DB::table('asset_buyout_quotes')->insert([
                    'asset_buyout_id' => $buyoutId,
                    'quote_amount' => $quote['amount'],
                    'remaining_rent' => $quote['rent'],
                    'quote_total' => (float) $quote['amount'] + (float) $quote['rent'],
                    'quoted_at' => $quote['date'],
                    'notes' => $quote['notes'],
                    'created_at' => $quote['date'],
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Leaves the backfilled rows in place: they are the record of work
        // that happened, not a schema artifact, and re-running `up` reconciles
        // them anyway.
    }

    private function resolveAsset(array $case): ?object
    {
        $query = DB::table('assets')->whereNull('deleted_at');

        if (! empty($case['tag'])) {
            $asset = (clone $query)->where('asset_tag', $case['tag'])->first();

            if ($asset) {
                return $asset;
            }
        }

        if (! empty($case['serial'])) {
            return (clone $query)->where('serial', $case['serial'])->first();
        }

        return null;
    }

    private function resolveBuyer(?string $email): ?int
    {
        if (! $email) {
            return null;
        }

        return DB::table('users')->where('email', $email)->whereNull('deleted_at')->value('id');
    }
};
