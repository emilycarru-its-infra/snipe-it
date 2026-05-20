<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Run consumables:link-printer-models once more so the HP/Xerox stub
 * consumables introduced in migration 2026_05_20_200000 land against
 * their printer asset models on first boot — instead of waiting for
 * the 02:30 scheduler.
 *
 * Carries the known IM C3500 → Ricoh IM C3510 alias so the Ricoh
 * cross-SKU stays linked when this run writes the log.
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
                @file_put_contents($path, "Ran ".now()->toIso8601String()." (post HP/Xerox seed):\n\n".$output);
                break;
            }
        }
    }

    public function down(): void
    {
        // No-op; bulk pivot backfill is not safely undoable.
    }
};
