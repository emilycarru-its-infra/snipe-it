<?php

namespace App\Services;

use App\Models\AssetModel;
use App\Models\CatalogItem;
use Illuminate\Support\Collection;

/**
 * Connects the catalog to the fleet: every row gets structured specs
 * parsed from its name, and a real asset model — the same models the
 * assets themselves live on, so the storefront shows the fleet's own
 * photos and the order chain lands on a model Snipe already knows.
 *
 * A price-list name and a Snipe model name describe the same product in
 * different dialects. CDW writes "Dell UltraSharp 27" U2724D | 120 Hz";
 * we hold "27" UltraSharp Thunderbolt Hub Monitor" with the maker in a
 * column of its own and U2724DE as the part number. So matching compares
 * the words the two names share rather than looking for one inside the
 * other, and leans on the facts around the name — manufacturer, category,
 * part number — before it leans on the words at all.
 *
 * It would rather leave a row unlinked than link it to the wrong thing:
 * an unlinked row is a visible gap in the model list, while a wrong link
 * silently sells the wrong machine. Every rule below is a reason to rule
 * a candidate out.
 *
 * Rows someone linked by hand are left alone unless asked to re-match.
 */
class CatalogRelink
{
    /**
     * A catalog section only ever maps onto certain kinds of asset model.
     * Components — SSDs, adapters, carts — are parts rather than assets;
     * nothing in the model list should claim them.
     */
    private const CATEGORY_GATE = [
        'Laptops' => ['Laptop'],
        'Desktops' => ['Desktop'],
        'Displays' => ['Display'],
        'Tablets' => ['Tablet'],
        'Scanners' => ['Scanner', 'Accessory'],
        'Accessories' => ['Accessory'],
        'Components' => ['Accessory'],
    ];

    /** The year each Apple silicon generation first shipped. */
    private const CHIP_YEAR = [1 => 2020, 2 => 2022, 3 => 2023, 4 => 2024, 5 => 2025];

    /**
     * Words that name a product line rather than describe one. An iPad and
     * an iPad Air are different products; an iPad and an iPad with 256GB
     * are the same product.
     */
    private const LINE_WORDS = ['pro', 'air', 'max', 'ultra', 'mini', 'plus', 'xdr', 'se', 'studio'];

    /** Words both dialects sprinkle everywhere, so they identify nothing. */
    private const STOP = [
        'the', 'with', 'and', 'for', 'inc', 'monitor', 'display', 'led', 'lcd',
        'ips', 'hd', 'widescreen', 'anti', 'glare', 'electronic', 'flatbed',
        'scanner', 'tower', 'desktop', 'laptop', 'wide', 'backlit', 'docking',
        'generation', 'gen', 'edition', 'ports', 'port', 'two', 'four', 'of',
        'a', 'tilt', 'adj', 'height', 'glass',
    ];

    public function __construct(private CatalogSpecParser $parser) {}

    /**
     * Rows someone linked by hand survive the default pass untouched. A
     * re-match replaces every link with what the rules say today, and that
     * includes clearing one it can no longer justify — a link the matcher
     * would not make is a link nobody should be trusting.
     *
     * @return array{parsed: int, matched: int, unmatched: array<string>}
     */
    public function relink(bool $rematchLinked = false): array
    {
        $models = AssetModel::with('category:id,name', 'manufacturer:id,name')
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'model_number', 'category_id', 'manufacturer_id']);

        $stats = ['parsed' => 0, 'matched' => 0, 'unmatched' => []];

        foreach (CatalogItem::withTrashed()->get() as $item) {
            $specs = $this->parser->parse($item->name);
            $item->fill($specs);
            $stats['parsed']++;

            if ($item->model_id === null || $rematchLinked) {
                $item->model_id = $this->match($item, $models)?->id;
            }

            $item->save();

            if ($item->model_id) {
                $stats['matched']++;
            } else {
                $stats['unmatched'][] = $item->name;
            }
        }

        return $stats;
    }

    /**
     * @param  Collection<int, AssetModel>  $models
     */
    public function match(CatalogItem $item, $models): ?AssetModel
    {
        $name = $item->name;
        $normalized = self::normalize($name);
        $tokens = self::tokens($name);

        $allowed = self::CATEGORY_GATE[$item->category] ?? null;
        $makers = self::leadingManufacturers($normalized, $models);

        $best = null;
        $bestScore = 0;

        foreach ($models as $model) {
            if ($allowed && ! in_array($model->category?->name, $allowed, true)) {
                continue;
            }

            // A leading maker is the maker. "Intel" further down a spec list
            // is the chip vendor, not who built the laptop.
            if ($makers && ! in_array(self::normalize($model->manufacturer->name ?? ''), $makers, true)) {
                continue;
            }

            $score = $this->score($item, $model, $tokens, $normalized);

            if ($score === null) {
                continue;
            }

            // Ties go to the newer model — the same product re-catalogued.
            if ($score > $bestScore || ($score === $bestScore && $best && $model->id > $best->id)) {
                $best = $model;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * How well one model answers one catalog row, or null if some fact
     * rules it out outright.
     *
     * @param  array<string>  $tokens
     */
    private function score(CatalogItem $item, AssetModel $model, array $tokens, string $normalized): ?int
    {
        $score = 0;
        $partNumber = trim(self::normalize($model->model_number ?? ''));

        // A part number the row quotes is as good as a signature. CDW often
        // quotes a stem of it ("U2724D" for a U2724DE), which is still far
        // stronger evidence than any number of shared words.
        if (strlen($partNumber) >= 4) {
            if (preg_match('/\b'.preg_quote($partNumber, '/').'\b/', $normalized)) {
                $score += 20;
            } else {
                foreach ($tokens as $token) {
                    if (strlen($token) >= 5 && (str_starts_with($partNumber, $token) || str_starts_with($token, $partNumber))) {
                        $score += 12;
                        break;
                    }
                }
            }
        }

        $modelName = $model->name;

        // A sized model of another size is another model.
        $size = self::screenSize($item->name);
        $modelSize = self::screenSize($modelName);

        if ($size !== null && $modelSize !== null && abs($size - $modelSize) > 0.05) {
            return null;
        }

        // Apple silicon generations are not interchangeable, and no machine
        // built before a chip existed can be that chip's model.
        $chip = self::chipGeneration($item->name);
        $modelChip = self::chipGeneration($modelName);

        if ($chip !== null && $modelChip !== null && $chip !== $modelChip) {
            return null;
        }

        $modelYear = self::year($modelName);

        if ($chip !== null && $modelChip === null && $modelYear !== null
            && $modelYear < (self::CHIP_YEAR[$chip] ?? 0)) {
            return null;
        }

        $generation = self::generation($item->name);
        $modelGeneration = self::generation($modelName);

        if ($generation !== null && $modelGeneration !== null && $generation !== $modelGeneration) {
            return null;
        }

        $modelTokens = self::tokens($modelName);
        $maker = self::normalize($model->manufacturer->name ?? '');

        if ($maker !== '' && in_array($maker, $tokens, true)) {
            $score += 2;
            $modelTokens = array_merge($modelTokens, self::tokens($maker));
        }

        // A version the model names and the row contradicts — a Thunderbolt 5
        // cable is not the Thunderbolt 4 one.
        if (array_diff(self::bareNumbers($modelTokens), self::bareNumbers($tokens))) {
            return null;
        }

        // A different line in the same family is a different product.
        if (array_diff(array_intersect($modelTokens, self::LINE_WORDS), $tokens)) {
            return null;
        }

        $shared = array_unique(array_intersect($tokens, $modelTokens));
        $designators = array_filter($shared, fn ($t) => self::isDesignator($t));
        $words = array_filter($shared, fn ($t) => ! self::isDesignator($t) && strlen($t) >= 3);

        $score += 4 * count($designators) + 2 * count($words);

        // A word only the model uses argues against it. A spec detail the
        // price list simply omits ("A17", "G1a") argues nothing either way.
        $score -= 2 * count(array_filter(
            array_unique(array_diff($modelTokens, $tokens)),
            fn ($t) => strlen($t) >= 2 && ! self::isDesignator($t)
        ));

        // Shared words alone are too cheap: every Lenovo is a "ThinkPad".
        // Something must pin the product down — its part number, a model
        // designator, or at least two words in agreement.
        if ($score < 12 && ! $designators && count($words) < 2) {
            return null;
        }

        return $score >= 4 ? $score : null;
    }

    /**
     * The manufacturers a name opens with, which is the only place a
     * price list puts the maker.
     *
     * @param  Collection<int, AssetModel>  $models
     * @return array<string>
     */
    private static function leadingManufacturers(string $normalized, $models): array
    {
        $lead = explode(' ', $normalized);
        $makers = [];

        foreach ($models as $model) {
            $maker = self::normalize($model->manufacturer->name ?? '');

            if ($maker === '' || in_array($maker, $makers, true)) {
                continue;
            }

            $words = explode(' ', $maker);

            if (array_slice($lead, 0, count($words)) === $words) {
                $makers[] = $maker;
            }
        }

        return $makers;
    }

    /** Both dialects reduced to bare lowercase words and numbers. */
    private static function normalize(?string $value): string
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Apple sprinkles U+2011 non-breaking hyphens through model names.
        $value = str_replace(["\u{2011}", "\u{2010}", "\u{201D}"], ['-', '-', '"'], $value);
        $value = mb_strtolower($value);

        // Generations are compared on their own, so they must not linger as
        // loose numbers in the words.
        $value = preg_replace('/\(\s*gen\s*\d+\s*\)/', ' ', $value);
        $value = preg_replace('/\b\d+(?:st|nd|rd|th)\s+(?:generation|edition)\b/', ' ', $value);

        // 15", 15 inch and 15-inch are one size; 15.0 and 15 are one number.
        $value = preg_replace_callback(
            '/(\d+(?:\.\d+)?)\s*(?:"|-?\s?inch\b)/',
            fn ($m) => (string) (float) $m[1].'in',
            $value
        );

        // "(1 m)" and "1m" are the same length of cable.
        $value = preg_replace('/\b(\d+(?:\.\d+)?)\s+m\b/', '$1m', $value);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    /**
     * @return array<string>
     */
    private static function tokens(?string $value): array
    {
        // A tier word right after a chip name ("A17 Pro", "M5 Max") describes
        // the chip, not the product line, so it must not read as one.
        $normalized = preg_replace('/\b([am]\d+)\s+(pro|max|ultra)\b/', '$1', self::normalize($value));

        return array_values(array_filter(
            explode(' ', $normalized),
            fn ($t) => $t !== ''
                && ! in_array($t, self::STOP, true)
                && ! preg_match('/^(19|20)\d\d$/', $t)
        ));
    }

    private static function screenSize(?string $value): ?float
    {
        return preg_match('/\b(\d+(?:\.\d+)?)in\b/', self::normalize($value), $m) ? (float) $m[1] : null;
    }

    private static function chipGeneration(?string $value): ?int
    {
        return preg_match('/\bm(\d)\b/', self::normalize($value), $m) ? (int) $m[1] : null;
    }

    private static function year(?string $value): ?int
    {
        preg_match_all('/\b(?:19|20)\d\d\b/', self::normalize($value), $m);

        return $m[0] ? max(array_map('intval', $m[0])) : null;
    }

    private static function generation(?string $value): ?int
    {
        $value = mb_strtolower(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (preg_match('/\bgen\s*(\d+)\b/', $value, $m)
            || preg_match('/\b(\d+)(?:st|nd|rd|th)\s+(?:generation|edition)\b/', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** "P620", "27in", "13000xl" — a token that names a specific product. */
    private static function isDesignator(string $token): bool
    {
        return preg_match('/\d/', $token) && preg_match('/[a-z]/', $token);
    }

    /**
     * @param  array<string>  $tokens
     * @return array<string>
     */
    private static function bareNumbers(array $tokens): array
    {
        return array_values(array_unique(array_filter($tokens, 'ctype_digit')));
    }
}
