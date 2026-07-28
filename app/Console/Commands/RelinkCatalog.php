<?php

namespace App\Console\Commands;

use App\Services\CatalogRelink;
use Illuminate\Console\Command;

class RelinkCatalog extends Command
{
    protected $signature = 'catalog:relink
        {--rematch-linked : Re-match rows that already have an asset model}';

    protected $description = 'Parse structured specs out of catalog item names and link every row to an asset model';

    public function handle(CatalogRelink $relinker): int
    {
        $stats = $relinker->relink((bool) $this->option('rematch-linked'));

        $this->info(sprintf('%d rows parsed, %d linked to a model.', $stats['parsed'], $stats['matched']));

        foreach ($stats['unmatched'] as $name) {
            $this->warn('No model match: '.$name);
        }

        return self::SUCCESS;
    }
}
