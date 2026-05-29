<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Broaden the user-agreement type by renaming `lease_end_purchase` to
 * `purchase`. The original code path only covered end-of-lease buyouts,
 * but the same agreement shape now also represents other one-time
 * laptop purchases (outright sales, accessory bundles, etc.) — keeping
 * the type generic prevents a second near-duplicate enum.
 *
 * `agreement_type` is a plain string column with no DB enum, so this is
 * a pure data backfill.
 *
 * NOT safely reversible. Once new code accepts `purchase` for rows
 * that were never the old `lease_end_purchase` type (outright sales,
 * accessory bundles), there is no way to tell at rollback time which
 * `purchase` rows were converted from `lease_end_purchase` and which
 * were created fresh. `down()` is therefore a no-op.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('user_agreements')
            ->where('agreement_type', 'lease_end_purchase')
            ->update(['agreement_type' => 'purchase']);
    }

    public function down(): void
    {
        // Intentionally no-op — see class docblock.
    }
};
