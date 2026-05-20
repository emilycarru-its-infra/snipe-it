<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Re-run the consumable→printer link backfill with the smarter matching
 * introduced in this PR.
 *
 * The first pass (migration 2026_05_20_130000) matched zero pairs because
 * the printer asset models include the manufacturer prefix (e.g. "Ricoh IM
 * 350F") that the toner consumables don't repeat ("IM 350 Black Toner").
 * The command now strips the manufacturer prefix and trailing letter
 * suffixes when building needles, so this run is the one that actually
 * populates the pivot.
 *
 * The artisan command is idempotent (syncWithoutDetaching), so even if the
 * earlier migration somehow had a hit, this re-run only adds gaps.
 *
 * Output lands at /home/snipeit-toner-backfill.log on the App Service
 * shared file system, fetchable via Kudu vfs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('consumables:link-printer-models');
        $output = Artisan::output();

        foreach (['/home', storage_path('logs')] as $dir) {
            if (is_dir($dir) && is_writable($dir)) {
                $path = $dir.'/snipeit-toner-backfill.log';
                @file_put_contents($path, "Ran ".now()->toIso8601String()." (smarter matching):\n\n".$output);
                break;
            }
        }
    }

    public function down(): void
    {
        // No-op; bulk pivot backfill is not safely undoable.
    }
};
