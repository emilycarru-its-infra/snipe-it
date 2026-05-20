<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Re-run consumables:link-printer-models with the rewritten matcher.
 *
 * Earlier passes left "Canon iPF680" → "iPF 680 ... Ink" unlinked because
 * the manufacturer-stripped form "ipf680" never gained a normalized
 * sibling (no whitespace/hyphens to strip). The matcher now operates
 * entirely on normalized strings, so single-word model names match
 * spaced consumable names automatically.
 *
 * Also passes the known IM C3500 → Ricoh IM C3510 alias so the IM C3500
 * toners remain linked when this migration overwrites the log file (the
 * Snipe DB has them from migration 2026_05_20_160000 already — this is
 * just to keep the visible log honest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('consumables:link-printer-models', [
            '--alias' => ['IM C3500=Ricoh IM C3510'],
        ]);
        $output = Artisan::output();

        foreach (['/home', storage_path('logs')] as $dir) {
            if (is_dir($dir) && is_writable($dir)) {
                $path = $dir.'/snipeit-toner-backfill.log';
                @file_put_contents($path, "Ran ".now()->toIso8601String()." (normalized matcher + alias):\n\n".$output);
                break;
            }
        }
    }

    public function down(): void
    {
        // No-op; bulk pivot backfill is not safely undoable.
    }
};
