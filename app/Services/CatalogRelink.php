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
 * Matching order per row: exact part number against models.model_number,
 * then a scored name match on family + screen size + chip generation
 * (newest model wins ties). Rows someone linked by hand are left alone
 * unless asked to re-match everything.
 */
class CatalogRelink
{
    public function __construct(private CatalogSpecParser $parser) {}

    /**
     * @return array{parsed: int, matched: int, unmatched: array<string>}
     */
    public function relink(bool $rematchLinked = false): array
    {
        $models = AssetModel::whereNull('deleted_at')->get(['id', 'name', 'model_number']);
        $stats = ['parsed' => 0, 'matched' => 0, 'unmatched' => []];

        foreach (CatalogItem::withTrashed()->get() as $item) {
            $specs = $this->parser->parse($item->name);
            $item->fill($specs);
            $stats['parsed']++;

            if ($item->model_id === null || $rematchLinked) {
                $model = $this->match($item, $models);

                if ($model) {
                    $item->model_id = $model->id;
                }
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
        // A part number the model list already carries is authoritative.
        if ($item->mfr_part_number) {
            $exact = $models->first(fn ($m) => $m->model_number
                && strcasecmp(trim($m->model_number), trim($item->mfr_part_number)) === 0);

            if ($exact) {
                return $exact;
            }
        }

        if (! $item->family) {
            return null;
        }

        $candidates = $models->filter(
            fn ($m) => stripos(self::normalize($m->name), self::normalize($item->family)) !== false
        );

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = null;
        $bestScore = -1;

        foreach ($candidates as $model) {
            $name = self::normalize($model->name);
            $score = 0;

            if ($item->screen_size) {
                if (preg_match('/\b'.preg_quote($item->screen_size, '/').'-inch\b/i', $name)) {
                    $score += 2;
                } elseif (preg_match('/\d+(\.\d+)?-inch/i', $name)) {
                    // A sized model of the wrong size is a wrong model.
                    continue;
                }
            }

            if ($item->chip) {
                // "M5" must not settle for "(M5 Pro)" — the tier is part
                // of the generation.
                $chipPattern = '/\b'.preg_quote($item->chip, '/').'\b(?!\s*(Pro|Max|Ultra))/i';

                if (preg_match('/\s(Pro|Max|Ultra)$/i', $item->chip)) {
                    $chipPattern = '/\b'.preg_quote($item->chip, '/').'\b/i';
                }

                if (preg_match($chipPattern, $name)) {
                    $score += 3;
                }
            }

            if ($score > $bestScore || ($score === $bestScore && $best && $model->id > $best->id)) {
                $best = $model;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** Apple sprinkles U+2011 non-breaking hyphens through model names. */
    private static function normalize(string $value): string
    {
        return str_replace(["\u{2011}", "\u{2010}"], '-', $value);
    }
}
