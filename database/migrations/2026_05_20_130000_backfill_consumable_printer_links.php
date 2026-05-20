<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * One-shot backfill of the consumables_asset_models pivot.
 *
 * Linux App Service custom containers don't ship with SSH, so we don't have
 * an interactive shell to run `php artisan consumables:link-printer-models`
 * by hand. Wrapping the artisan call in a migration lets Snipe-IT's startup
 * script execute it once on the next container boot, with Laravel's
 * migration ledger making sure we don't re-run it accidentally.
 *
 * The artisan command itself is idempotent (syncWithoutDetaching), so even
 * if someone re-applies the migration via `migrate:refresh` later, nothing
 * destructive happens — links get re-asserted, nothing detached.
 *
 * Output is captured to /home/snipeit-toner-backfill.log on the App Service
 * shared file system so it can be reviewed via the Kudu vfs API after the
 * deploy lands. The path is exposed at:
 *   https://<scm-host>/api/vfs/snipeit-toner-backfill.log
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('consumables:link-printer-models');
        $output = Artisan::output();

        // Pick the first writable destination so this still works in environments
        // that aren't Linux App Service (e.g. local dev, plain Docker hosts).
        foreach (['/home', storage_path('logs')] as $dir) {
            if (is_dir($dir) && is_writable($dir)) {
                $path = $dir.'/snipeit-toner-backfill.log';
                @file_put_contents($path, "Ran ".now()->toIso8601String().":\n\n".$output);
                break;
            }
        }
    }

    public function down(): void
    {
        // Down is a no-op: there's no safe way to undo a bulk pivot backfill
        // without knowing which links were created by this migration vs by
        // hand afterwards. The artisan command supports --replace if you need
        // to wipe and re-seed.
    }
};
