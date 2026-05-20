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
        {--toner-category=toner,ink,cartridge,head,waste,supplies : Comma-separated substrings matched against consumable category names (any-match, case-insensitive)}
        {--printer-category=printer : Substring matched against asset-model categories (case-insensitive)}
        {--min-model-length=4 : Skip printer-model names shorter than this to avoid spurious matches}
        {--alias=* : Extra "haystack-substring=printer-model-name" pairs. Repeatable. Use for SKU mismatches the algorithm can\'t derive (e.g. Ricoh toner part numbers that don\'t equal the printer model name).}
        {--replace : Replace existing links (use sync) instead of additive (syncWithoutDetaching)}
        {--dry-run : Report matches without writing anything}';

    protected $description = 'Backfill consumable → printer asset-model compatibility from name matches.';

    public function handle(): int
    {
        $tonerCategoryNeedles  = collect(explode(',', (string) $this->option('toner-category')))
            ->map(fn ($s) => strtolower(trim($s)))
            ->filter()
            ->unique()
            ->values();
        $printerCategoryNeedle = strtolower((string) $this->option('printer-category'));
        $minModelLength        = max(1, (int) $this->option('min-model-length'));
        $replace               = (bool) $this->option('replace');
        $dryRun                = (bool) $this->option('dry-run');

        $tonerCategoryQuery = Category::query()->where('category_type', 'consumable');
        $tonerCategoryQuery->where(function ($q) use ($tonerCategoryNeedles) {
            foreach ($tonerCategoryNeedles as $needle) {
                $q->orWhereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%']);
            }
        });
        $tonerCategoryIds = $tonerCategoryQuery->pluck('id');

        $printerCategoryIds = Category::query()
            ->where('category_type', 'asset')
            ->whereRaw('LOWER(name) LIKE ?', ['%'.$printerCategoryNeedle.'%'])
            ->pluck('id');

        if ($tonerCategoryIds->isEmpty()) {
            $this->error("No consumable category matched '{$tonerCategoryNeedles->implode(',')}'. Aborting.");
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

        $printerModels = AssetModel::with('manufacturer:id,name')
            ->whereIn('category_id', $printerCategoryIds)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'model_number', 'manufacturer_id'])
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

        // Build several needles per printer model so we don't miss obvious
        // matches because of naming-style differences:
        //   1. Full model name as-is        ("Ricoh IM 5000")
        //   2. Manufacturer stripped        ("IM 5000")          — consumables rarely repeat the brand
        //   3. Model number column          ("IM 5000F")         — manufacturer's part number, sometimes used verbatim
        //   4. Trailing 1-2 capital letters dropped from the stripped form ("IM 350" from "IM 350F")
        //
        // Every needle is normalized (lowercased + whitespace + hyphens
        // stripped) and matched against the equally-normalized haystack.
        // That neutralizes separator-style differences in one shot — printer
        // "Canon iPF680" matches consumable "iPF 680 PFI-207 Ink", "Canon
        // GP 200" matches "iPF GP-200 ...", "Canon PRO 2000" matches
        // "Pro2000-2100 ...", and "Ricoh IM 5000" still matches "IM 5000
        // Black Toner". One pass, no carve-outs.
        //
        // Sorted longest-first across all variants so a specific needle
        // beats a generic one (e.g. "IM C300F" before "IM 300").
        $normalize = static fn (string $s): string =>
            strtolower(preg_replace('/[\s\-]+/u', '', trim($s)));

        $rawNeedles = [];
        foreach ($printerModels as $m) {
            $variants = [];
            $variants[] = $m->name;
            if ($m->manufacturer && $m->manufacturer->name) {
                $stripped = trim(preg_replace('/^'.preg_quote($m->manufacturer->name, '/').'\s+/i', '', $m->name));
                if ($stripped !== '' && $stripped !== $m->name) {
                    $variants[] = $stripped;
                    // Drop trailing 1-2 capital letters (e.g. F, FB) — common suffix
                    // family marker on multifunction printers.
                    $trimmed = trim(preg_replace('/\s*[A-Z]{1,2}\s*$/', '', $stripped));
                    if ($trimmed !== '' && $trimmed !== $stripped) {
                        $variants[] = $trimmed;
                    }
                }
            }
            if (! empty($m->model_number)) {
                $variants[] = $m->model_number;
            }
            foreach (array_unique(array_filter($variants)) as $variant) {
                $needle = $normalize($variant);
                if (mb_strlen($needle) < $minModelLength) {
                    continue;
                }
                $rawNeedles[] = ['id' => $m->id, 'name' => $m->name, 'needle' => $needle];
            }
        }

        // Explicit aliases — for SKU mismatches that the auto-needle pipeline
        // can't derive (e.g. Ricoh sells "IM C3500" toner that fits the
        // "IM C3510" printer). Format: --alias="haystack-substring=printer-model-name".
        // The right-hand side is matched case-insensitively against the
        // asset-model name; first hit wins. Left side is normalized too so
        // the user doesn't have to think about separator style.
        foreach ((array) $this->option('alias') as $alias) {
            if (! str_contains($alias, '=')) {
                $this->warn("Ignoring malformed --alias '{$alias}' (expected LEFT=RIGHT).");
                continue;
            }
            [$left, $right] = array_map('trim', explode('=', $alias, 2));
            if ($left === '' || $right === '') {
                $this->warn("Ignoring empty --alias '{$alias}'.");
                continue;
            }
            $model = $printerModels->first(fn ($m) => strcasecmp($m->name, $right) === 0);
            if (! $model) {
                $this->warn("Alias '{$alias}': no printer model matched '{$right}'. Skipping.");
                continue;
            }
            $rawNeedles[] = ['id' => $model->id, 'name' => $model->name, 'needle' => $normalize($left)];
        }

        // Drop duplicate (id, needle) pairs so the preview-table count stays
        // honest when, say, the full name and the manufacturer-stripped name
        // normalize to the same thing.
        $needles = collect($rawNeedles)
            ->unique(fn ($row) => $row['id'].'|'.$row['needle'])
            ->sortByDesc(fn ($row) => mb_strlen($row['needle']))
            ->values();

        $previewRows = [];
        $newLinks    = 0;
        $touched     = 0;
        $unmatched   = [];

        foreach ($consumables as $consumable) {
            $hay = $normalize($consumable->name);
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
