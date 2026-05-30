<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retag the contracts the asset→contract bridge migration created
 * with the more descriptive source='snipe'. PR #129 stamped them
 * 'manual' as a placeholder; per follow-up call, 'snipe' is the
 * correct identifier for "owned by Snipe-IT itself, not by TDX/CSI".
 *
 * Targeted: only contracts created by the linker — name matches
 * the Devices Leases FY pattern AND current source is 'manual'.
 * Other 'manual' contracts (hand-curated, predating this code)
 * stay alone.
 *
 * Reversible: down() sets them back to 'manual' but only for rows
 * matching the same name pattern, so no foreign data is touched.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('contracts')
            ->where('source', 'manual')
            ->where('name', 'like', 'Devices Leases FY%')
            ->update(['source' => 'snipe']);
    }

    public function down(): void
    {
        DB::table('contracts')
            ->where('source', 'snipe')
            ->where('name', 'like', 'Devices Leases FY%')
            ->update(['source' => 'manual']);
    }
};
