<?php

namespace App\Services;

use App\Models\CatalogItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps the catalog honest against Apple's own Canadian store.
 *
 * Apple's buy pages embed the complete product-selection tree — every
 * orderable configuration with its part number, CAD price, chip core
 * counts, colour, screen size and display finish — as a JSON bootstrap.
 * This walks those pages and refreshes any catalog row whose
 * manufacturer part number Apple recognises: the spec columns are
 * corrected from Apple's data, which knows the exact CPU/GPU binning
 * where our name parsing only knows the chip's base config, and the
 * retail price fills the estimate only when we have no price of our own.
 *
 * Price is deliberately not a refresh. We never buy at Apple retail —
 * the reseller's price list is our negotiated bundle rate with the
 * warranty already in it — so Apple's number overwriting ours made the
 * catalog less honest rather than more.
 *
 * CTO rows carry CDW's own Z-codes, not Apple part numbers, so they pass
 * through untouched — their prices only ever come from quotes.
 */
class AppleStoreSync
{
    /** The buy pages that cover what the store shelves carry. */
    public const DEFAULT_PAGES = [
        'https://www.apple.com/ca/shop/buy-mac/macbook-pro/14-inch',
        'https://www.apple.com/ca/shop/buy-mac/macbook-pro/16-inch',
        'https://www.apple.com/ca/shop/buy-mac/macbook-air/13-inch',
        'https://www.apple.com/ca/shop/buy-mac/macbook-air/15-inch',
        'https://www.apple.com/ca/shop/buy-mac/imac',
        'https://www.apple.com/ca/shop/buy-mac/mac-mini',
        'https://www.apple.com/ca/shop/buy-mac/mac-studio',
        'https://www.apple.com/ca/shop/buy-mac/apple-studio-display',
        'https://www.apple.com/ca/shop/buy-ipad/ipad-pro',
        'https://www.apple.com/ca/shop/buy-ipad/ipad-air',
        'https://www.apple.com/ca/shop/buy-ipad/ipad',
        'https://www.apple.com/ca/shop/buy-ipad/ipad-mini',
    ];

    private const COLOR_NAMES = [
        'silver' => 'Silver', 'black' => 'Black', 'spaceblack' => 'Space Black',
        'spacegray' => 'Space Gray', 'spacegrey' => 'Space Gray', 'gray' => 'Gray', 'grey' => 'Gray',
        'blue' => 'Blue', 'pink' => 'Pink', 'purple' => 'Purple', 'yellow' => 'Yellow',
        'orange' => 'Orange', 'green' => 'Green', 'starlight' => 'Starlight', 'midnight' => 'Midnight',
    ];

    /**
     * @param  list<string>  $pages
     * @return array{pages: int, page_errors: array<string>, products: int,
     *               updated: int, unmatched: int}
     */
    public function sync(array $pages = [], bool $dryRun = false): array
    {
        $pages = $pages ?: self::DEFAULT_PAGES;
        $stats = ['pages' => 0, 'page_errors' => [], 'products' => 0, 'updated' => 0, 'unmatched' => 0];

        foreach ($pages as $url) {
            try {
                $data = $this->fetchSelectionData($url);
            } catch (\Throwable $e) {
                $stats['page_errors'][] = $url.' — '.$e->getMessage();

                continue;
            }

            $stats['pages']++;

            if (! $dryRun) {
                Storage::disk('local')->put(
                    'apple-store/'.preg_replace('/[^a-z0-9-]+/', '-', str_replace('https://www.apple.com/ca/shop/', '', $url)).'.json',
                    json_encode($data, JSON_PRETTY_PRINT)
                );
            }

            $prices = $data['mainDisplayValues']['prices'] ?? [];

            foreach ($data['products'] ?? [] as $product) {
                $partNumber = $product['btrOrFdPartNumber'] ?? null;

                if (! $partNumber) {
                    continue;
                }

                $stats['products']++;

                $item = $this->findByPartNumber($partNumber);

                if (! $item) {
                    $stats['unmatched']++;

                    continue;
                }

                $this->apply($item, $product, $prices, $dryRun);
                $stats['updated']++;
            }
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $prices
     */
    private function apply(CatalogItem $item, array $product, array $prices, bool $dryRun): void
    {
        $dimensions = $product['dimensions'] ?? [];

        $amount = $prices[$product['priceKey'] ?? '']['amount'] ?? null;

        // Apple's retail price only fills a blank. We do not buy at retail:
        // the reseller's price list is bundle pricing at our negotiated
        // rate, warranty included, and it is always the lower number — the
        // two rows Apple had overwritten were out by $99 and $699, both
        // upward, which is a real quote faculty were reading and a
        // comparable the intake form was quoting back at them.
        //
        // The specs below are a different matter and still worth taking:
        // Apple knows the exact CPU/GPU binning that our name parsing can
        // only guess at from the chip's base configuration.
        if ($amount !== null && (float) $amount > 0 && $item->estimated_cost === null) {
            $item->estimated_cost = (float) $amount;

            if ($item->price_type !== 'quoted') {
                $item->price_type = 'list';
            }
        }

        if ($chipKey = ($dimensions['processor-dimensionChip'] ?? null)) {
            $item->chip = self::chipName($chipKey);
        }

        // "m5pro-15-16" — the exact binning of this configuration, which
        // beats the base-config guess the name parser made.
        if (preg_match('/-(\d+)-(\d+)$/', $dimensions['processor-dimensionChip-cpuCoreCount-gpuCoreCount'] ?? '', $m)) {
            $item->spec_cpu = $m[1].'-core CPU';
            $item->spec_gpu = $m[2].'-core GPU';
        }

        if ($colorKey = ($dimensions['chassis-dimensionColor'] ?? null)) {
            $item->color = self::COLOR_NAMES[strtolower($colorKey)] ?? ucfirst($colorKey);
        }

        if (preg_match('/^(\d+(?:\.\d+)?)inch$/', $dimensions['chassis-dimensionScreensize'] ?? '', $m)) {
            $item->screen_size = $m[1];
        }

        if ($finish = ($dimensions['display-dimensionFinish'] ?? null)) {
            $item->display_finish = stripos($finish, 'nano') !== false ? 'nano' : 'standard';
        }

        if (! $dryRun && $item->isDirty()) {
            $item->save();
        }
    }

    /**
     * Exact part number first; failing that, the same model code in a
     * different region ("MDE54LL/A" vs "MDE54CL/A" are the same machine).
     */
    private function findByPartNumber(string $partNumber): ?CatalogItem
    {
        $exact = CatalogItem::where('mfr_part_number', $partNumber)->first();

        if ($exact) {
            return $exact;
        }

        if (preg_match('#^([A-Z0-9]{5})[A-Z]{1,2}/A$#', $partNumber, $m)) {
            return CatalogItem::where('mfr_part_number', 'like', $m[1].'%/A')->first();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSelectionData(string $url): array
    {
        $html = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
            'Accept-Language' => 'en-CA',
        ])->timeout(30)->get($url)->throw()->body();

        $marker = strpos($html, 'productSelectionData:');

        if ($marker === false) {
            throw new \RuntimeException('no productSelectionData bootstrap on page');
        }

        $start = strpos($html, '{', $marker);
        $json = $this->balancedJson($html, $start);
        $data = json_decode($json, true);

        if (! is_array($data) || empty($data['products'])) {
            throw new \RuntimeException('productSelectionData did not parse');
        }

        return $data;
    }

    /** The JSON object starting at $start, found by brace balancing. */
    private function balancedJson(string $html, int $start): string
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($html);

        for ($i = $start; $i < $length; $i++) {
            $char = $html[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($html, $start, $i - $start + 1);
                }
            }
        }

        throw new \RuntimeException('unterminated JSON in page');
    }

    public static function chipName(string $key): string
    {
        return (string) preg_replace_callback(
            '/^(m\d+)(pro|max|ultra)?$/i',
            fn ($m) => strtoupper($m[1]).(isset($m[2]) ? ' '.ucfirst(strtolower($m[2])) : ''),
            strtolower(trim($key))
        );
    }
}
