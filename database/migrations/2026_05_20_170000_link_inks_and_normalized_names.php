<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Re-run consumables:link-printer-models with the broader defaults
 * introduced in this PR:
 *   - --toner-category now defaults to a list (toner,ink,cartridge,head,
 *     waste,supplies), so Canon iPF/PRO inks land alongside Ricoh toners.
 *   - Matching now also tries a "normalized" form with whitespace and
 *     hyphens stripped, so "Canon iPF680" matches "iPF 680 … Ink" and
 *     "Canon GP 200" matches "iPF GP-200 …".
 *
 * Idempotent (syncWithoutDetaching), so this is safe alongside the earlier
 * passes (#44, #45, #46). Output to /home/snipeit-toner-backfill.log.
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
                @file_put_contents($path, "Ran ".now()->toIso8601String()." (broader categories + normalized matching):\n\n".$output);
                break;
            }
        }
    }

    public function down(): void
    {
        // No-op; bulk pivot backfill is not safely undoable.
    }
};
