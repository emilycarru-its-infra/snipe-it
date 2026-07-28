<?php

namespace App\Console\Commands;

use App\Services\AppleStoreSync;
use Illuminate\Console\Command;

class SyncAppleStore extends Command
{
    protected $signature = 'catalog:sync-apple
        {--page=* : Specific buy-page URLs instead of the default set}
        {--dry-run : Fetch and report without writing}';

    protected $description = 'Refresh catalog prices and specs from apple.com/ca buy pages';

    public function handle(AppleStoreSync $sync): int
    {
        $stats = $sync->sync($this->option('page'), (bool) $this->option('dry-run'));

        $this->info(sprintf(
            '%d pages, %d products seen, %d catalog rows refreshed, %d Apple products not in the catalog.',
            $stats['pages'],
            $stats['products'],
            $stats['updated'],
            $stats['unmatched']
        ));

        foreach ($stats['page_errors'] as $error) {
            $this->warn($error);
        }

        return $stats['page_errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
