<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-contract source-of-truth flag for the bidirectional TDX sync.
 *
 * `ssot` names the system whose data wins for this contract: 'tdx' means the
 * TDX->Snipe ingest overwrites the row every run; 'snipe' means the row is
 * authored here and the Snipe->TDX push mirrors it upstream. Flipping the
 * value reverses the sync direction for that one contract — both Azure
 * Functions read the flag and stand down on rows the other side owns. Null
 * means the row does not participate in the sync (manual / synthesized).
 *
 * `service_catalogue` promotes the last remaining TDX custom attribute that
 * was still riding in the k/v sidecar, so it becomes filterable like the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('ssot', 8)->nullable()->index()->after('source');
            $table->string('service_catalogue')->nullable()->after('service_offering');
        });

        // Backfill from the current implicit ownership: TDX-sourced rows are
        // TDX-owned, snipe-sourced rows (the lease register) are Snipe-owned.
        DB::table('contracts')->where('source', 'tdx')->update(['ssot' => 'tdx']);
        DB::table('contracts')->where('source', 'snipe')->update(['ssot' => 'snipe']);
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['ssot', 'service_catalogue']);
        });
    }
};
