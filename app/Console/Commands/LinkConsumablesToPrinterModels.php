<?php

namespace App\Console\Commands;

use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Consumable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Backfill the consumables_asset_models pivot by matching toner consumables
 * against printer asset models based on substring-presence of the model name
 * inside the consumable name. Idempotent: uses syncWithoutDetaching so any
 * pairings already set by hand are preserved, and re-running only adds the
 * gaps. Safe to validate with --dry-run before applying.
 */
class LinkConsumablesToPrinterModels extends Command
{
    protected $signature = 'consumables:link-printer-models
        {--toner-category=toner : Substring matched against consumable categories (case-insensitive)}
        {--printer-category=printer : Substring matched against asset-model categories (case-insensitive)}
        {--min-model-length=4 : Skip printer-model names shorter than this to avoid spurious matches}
        {--replace : Replace existing links (use sync) instead of additive (syncWithoutDetaching)}
        {--dry-run : Report matches without writing anything}';

    protected $description = 'Backfill consumable → printer asset-model compatibility from name matches.';

    public function handle(): int
    {
        $tonerCategoryNeedle   = strtolower((string) $this->option('toner-category'));
        $printerCategoryNeedle = strtolower((string) $this->option('printer-category'));
        $minModelLength        = max(1, (int) $this->option('min-model-length'));
        $replace               = (bool) $this->option('replace');
        $dryRun                = (bool) $this->option('dry-run');

        $tonerCategoryIds = Category::query()
            ->where('category_type', 'consumable')
            ->whereRaw('LOWER(name) LIKE ?', ['%'.$tonerCategoryNeedle.'%'])
            ->pluck('id');

        $printerCategoryIds = Category::query()
            ->where('category_type', 'asset')
            ->whereRaw('LOWER(name) LIKE ?', ['%'.$printerCategoryNeedle.'%'])
            ->pluck('id');

        if ($tonerCategoryIds->isEmpty()) {
            $this->error("No consumable category matched '{$tonerCategoryNeedle}'. Aborting.");
            return self::FAILURE;
        }
        if ($printerCategoryIds->isEmpty()) {
            $this->error("No asset category matched '{$printerCategoryNeedle}'. Aborting.");
            return self::FAILURE;
        }

        $this->line(sprintf(
            'Scoping to %d toner %s and %d printer %s.',
            $tonerCategoryIds->count(),
            Str::plural('category', $tonerCategoryIds->count()),
            $printerCategoryIds->count(),
            Str::plural('category', $printerCategoryIds->count()),
        ));

        $printerModels = AssetModel::whereIn('category_id', $printerCategoryIds)
            ->whereNull('deleted_at')
            ->get(['id', 'name'])
            ->filter(fn ($m) => mb_strlen(trim($m->name)) >= $minModelLength)
            ->values();

        if ($printerModels->isEmpty()) {
            $this->warn('No printer models above the minimum length. Nothing to do.');
            return self::SUCCESS;
        }

        $consumables = Consumable::whereIn('category_id', $tonerCategoryIds)
            ->with('compatibleModels:id')
            ->get(['id', 'name', 'category_id']);

        if ($consumables->isEmpty()) {
            $this->warn('No toner consumables found. Nothing to do.');
            return self::SUCCESS;
        }

        // Precompute lowercase needles and an index by length descending so the
        // most-specific names match first (e.g. "M428fdn" before "M428").
        $needles = $printerModels
            ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'needle' => strtolower(trim($m->name))])
            ->sortByDesc(fn ($row) => mb_strlen($row['needle']))
            ->values();

        $previewRows = [];
        $newLinks    = 0;
        $touched     = 0;
        $unmatched   = [];

        foreach ($consumables as $consumable) {
            $hay = strtolower($consumable->name);
            $existing = $consumable->compatibleModels->pluck('id')->all();
            $matched = [];

            foreach ($needles as $needle) {
                if (str_contains($hay, $needle['needle'])) {
                    $matched[$needle['id']] = $needle['name'];
                }
            }

            if (empty($matched)) {
                $unmatched[] = $consumable->name;
                continue;
            }

            $matchedIds = array_keys($matched);
            $additions  = array_diff($matchedIds, $existing);
            $removals   = $replace ? array_diff($existing, $matchedIds) : [];

            $previewRows[] = [
                'consumable' => Str::limit($consumable->name, 60),
                'matches'    => implode(', ', $matched),
                'new'        => count($additions),
                'kept'       => count(array_intersect($existing, $matchedIds)),
                'removed'    => count($removals),
            ];

            if (! empty($additions) || ! empty($removals)) {
                $touched++;
                $newLinks += count($additions);
            }

            if (! $dryRun) {
                if ($replace) {
                    $consumable->compatibleModels()->sync($matchedIds);
                } else {
                    $consumable->compatibleModels()->syncWithoutDetaching($matchedIds);
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Consumable', 'Matched printer models', 'New', 'Kept', 'Removed'],
            $previewRows,
        );

        $this->newLine();
        $this->info(sprintf(
            '%s%d consumable%s touched, %d new link%s%s.',
            $dryRun ? '[dry-run] ' : '',
            $touched,
            Str::plural('', $touched),
            $newLinks,
            Str::plural('', $newLinks),
            $replace ? ' (replace mode)' : '',
        ));

        if (! empty($unmatched)) {
            $this->newLine();
            $this->warn(sprintf('%d consumable%s had no model match:', count($unmatched), Str::plural('', count($unmatched))));
            foreach ($unmatched as $name) {
                $this->line('  • '.$name);
            }
            $this->newLine();
            $this->line('Hint: check that the printer model name appears verbatim somewhere in the consumable name, or lower --min-model-length.');
        }

        return self::SUCCESS;
    }
}
