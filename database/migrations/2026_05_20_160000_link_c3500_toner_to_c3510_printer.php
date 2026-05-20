<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Wire the "IM C3500" toner SKUs to the "Ricoh IM C3510" printer model.
 *
 * Ricoh sells the IM C3500 toner cartridges for use in the IM C3510
 * printer family — the part-number doesn't equal the printer model name,
 * so the auto-needle pipeline in consumables:link-printer-models can't
 * derive the link. This migration calls the command with an explicit
 * --alias so the 5 IM C3500 toners (Black/Cyan/Magenta/Yellow + Waste)
 * land against IM C3510.
 *
 * Idempotent like the earlier two passes — syncWithoutDetaching keeps
 * anything that's already been linked.
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
                @file_put_contents($path, "Ran ".now()->toIso8601String()." (C3500→C3510 alias):\n\n".$output);
                break;
            }
        }
    }

    public function down(): void
    {
        // No-op; bulk pivot backfill is not safely undoable.
    }
};
