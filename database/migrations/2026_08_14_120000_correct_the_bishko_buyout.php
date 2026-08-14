<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Leslie Bishko buyout, corrected.
 *
 * The 2026 backfill transcribed this one from a mail thread that never named
 * a device and never stated a split, so it landed as a person, $899.00, and
 * no asset. Both gaps are now closed: the checkin that ended her offboarding
 * on 2026-08-13 identifies the device as L003565, and the settlement is the
 * same shape as the Bussigel case — the buyer pays the buyout price by
 * payroll deduction and ECU absorbs the remaining rent, because the lease
 * runs to 2027-09-01.
 *
 * The backfill's own case list has been corrected in place as well, so a
 * database built from scratch never sees the wrong figures. This migration
 * exists for the databases that already took the old ones. Matched on the
 * buyer and keyed off "no asset linked", so it is inert once applied and
 * inert on any database where the backfill found nothing to write.
 */
return new class extends Migration
{
    private const BUYER_EMAIL = 'lbishko@ecuad.ca';

    private const ASSET_TAG = 'L003565';

    private const BUYER_PAYS = 899.99;

    private const ECU_ABSORBS = 872.01;

    public function up(): void
    {
        $buyerId = DB::table('users')->where('email', self::BUYER_EMAIL)->whereNull('deleted_at')->value('id');
        $asset = DB::table('assets')->where('asset_tag', self::ASSET_TAG)->whereNull('deleted_at')->first();

        if (! $buyerId || ! $asset) {
            return;
        }

        $buyout = DB::table('asset_buyouts')
            ->where('buyer_id', $buyerId)
            ->whereNull('asset_id')
            ->first();

        if (! $buyout) {
            return;
        }

        $total = self::BUYER_PAYS + self::ECU_ABSORBS;

        DB::table('asset_buyouts')->where('id', $buyout->id)->update([
            'asset_id' => $asset->id,
            'lessor_id' => $asset->lessor_id,
            'quote_amount' => self::BUYER_PAYS,
            'remaining_rent' => self::ECU_ABSORBS,
            'quote_total' => $total,
            'buyer_amount' => self::BUYER_PAYS,
            'ecu_amount' => self::ECU_ABSORBS,
            'notes' => 'Opened by HR for a September retirement. Buyout confirmed at offboarding on 2026-08-13. Buyer pays $899.99 by payroll deduction; ECU absorbs the $872.01 remaining rent on a lease running to 2027-09-01. Payment never confirmed.',
            'updated_at' => now(),
        ]);

        // The quote row carried the same wrong figures; it is one transcribed
        // quote, not a superseded one, so it is corrected rather than added to.
        DB::table('asset_buyout_quotes')
            ->where('asset_buyout_id', $buyout->id)
            ->update([
                'quote_amount' => self::BUYER_PAYS,
                'remaining_rent' => self::ECU_ABSORBS,
                'quote_total' => $total,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // A correction to a transcription, not a schema change — reverting it
        // would only put wrong numbers back.
    }
};
