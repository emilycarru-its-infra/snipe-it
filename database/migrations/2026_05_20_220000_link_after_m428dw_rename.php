<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Final pass of consumables:link-printer-models after the M428dw consumable
 * was renamed from 'LaserJet Pro MFP M428dw Black Toner' to
 * 'HP LaserJet Pro MFP M428dw Black Toner' via the API. The rename gives
 * the matcher a needle it can find (the printer asset model lives under
 * manufacturer 'HP Inc Printers', so manufacturer-strip wasn't producing
 * a usable form). Idempotent — only the new link gets added.
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
                @file_put_contents($path, "Ran ".now()->toIso8601String()." (post M428dw rename):\n\n".$output);
                break;
            }
        }
    }

    public function down(): void
    {
        // No-op; bulk pivot backfill is not safely undoable.
    }
};
