<?php

namespace App\Services\ProductIdentity;

use App\Models\ProductIdentity;
use App\Models\ProductIdentityAlias;
use App\Services\FiscalYear;
use Illuminate\Support\Collection;

/**
 * Turns an observed application name into the product identity it belongs
 * to, and from there into the licenses that cover it.
 *
 * Matching is version-independent: names and exact/prefix patterns are both
 * normalised (lower-cased, trailing version numbers, bracketed qualifiers and
 * .app/.exe suffixes removed) before they are compared, so "Rhino 8",
 * "Rhino 7" and "rhino.exe" all meet the alias "Rhino". A regex alias is
 * tried against the raw name, for the cases normalising cannot reach.
 *
 * When several aliases match, an exact match beats a prefix, a prefix beats a
 * regex, a longer prefix beats a shorter one, and a platform-specific alias
 * beats an "any" alias.
 */
class ProductResolver
{
    private const MATCH_RANK = ['exact' => 0, 'prefix' => 1, 'regex' => 2];

    /** @var Collection<int, ProductIdentityAlias>|null */
    private ?Collection $aliases = null;

    /** @var array<string, ?ProductIdentity> */
    private array $memo = [];

    public static function normalize(string $name): string
    {
        $value = mb_strtolower(trim($name));
        $value = preg_replace('/\.(app|exe)$/u', '', $value);

        do {
            $before = $value;
            // Bracketed qualifiers: "(x64)", "[64-bit]".
            $value = preg_replace('/\s*[(\[][^)\]]*[)\]]\s*$/u', '', $value);
            // Trailing version tokens: " 8", " 2025", " v10.2", "_2024.1".
            $value = preg_replace('/[\s_-]+v?\d+(?:[._-]\d+)*$/u', '', $value);
            $value = trim($value);
        } while ($value !== $before && $value !== '');

        return preg_replace('/\s+/u', ' ', $value);
    }

    public static function normalizePlatform(?string $platform): ?string
    {
        $value = mb_strtolower(trim((string) $platform));

        return match (true) {
            $value === '' => null,
            in_array($value, ['mac', 'macos', 'osx', 'os x', 'darwin', 'macintosh'], true) => 'macos',
            in_array($value, ['win', 'windows', 'win32', 'win64'], true) => 'windows',
            default => $value,
        };
    }

    public function resolve(string $name, ?string $platform = null): ?ProductIdentity
    {
        $platform = self::normalizePlatform($platform);
        $key = ($platform ?? '*').'|'.$name;

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $normalized = self::normalize($name);
        $best = null;
        $bestScore = null;

        foreach ($this->aliases() as $alias) {
            if ($platform !== null && $alias->platform !== 'any' && $alias->platform !== $platform) {
                continue;
            }
            if (! $this->matches($alias, $name, $normalized)) {
                continue;
            }

            $score = [
                self::MATCH_RANK[$alias->match_type] ?? 9,
                -mb_strlen($alias->pattern),
                $alias->platform === 'any' ? 1 : 0,
                $alias->id,
            ];
            if ($bestScore === null || $score < $bestScore) {
                $best = $alias;
                $bestScore = $score;
            }
        }

        return $this->memo[$key] = $best?->productIdentity;
    }

    /**
     * Split a batch of observed applications into the products they resolve
     * to and the ones nothing claims.
     *
     * Each observation is an array with `name` and optionally `platform`,
     * `usage` (whatever the caller measures: hours, launches) and `devices`.
     * Unmapped applications below `$minUsage` are counted rather than listed,
     * so the report says how much it left out instead of dropping it.
     *
     * @param  iterable<array{name: string, platform?: ?string, usage?: int|float|null, devices?: ?int}>  $observations
     * @return array{fiscal_year: ?string, mapped: array<int, array>, unmapped: array<int, array>, unmapped_below_threshold: int}
     */
    public function partition(iterable $observations, ?string $fiscalYear = null, float $minUsage = 0): array
    {
        $fy = FiscalYear::normalize($fiscalYear);
        $mapped = [];
        $unmapped = [];
        $below = 0;

        foreach ($observations as $row) {
            $name = trim((string) $row['name']);
            if ($name === '') {
                continue;
            }

            $app = [
                'name'     => $name,
                'platform' => self::normalizePlatform($row['platform'] ?? null),
                'usage'    => (float) ($row['usage'] ?? 0),
                'devices'  => (int) ($row['devices'] ?? 0),
            ];

            $identity = $this->resolve($name, $app['platform']);

            if ($identity === null) {
                if ($app['usage'] >= $minUsage) {
                    $unmapped[] = $app;
                } else {
                    $below++;
                }

                continue;
            }

            if (! isset($mapped[$identity->id])) {
                $licenses = $identity->licensesFor($fy);
                $mapped[$identity->id] = [
                    'product_identity_id' => $identity->id,
                    'name'                => $identity->name,
                    'publisher'           => $identity->publisher,
                    'procurement_name'    => $identity->procurement_name,
                    'licensed'            => $licenses->isNotEmpty(),
                    'licenses'            => $licenses->map(fn ($l) => [
                        'id'          => $l->id,
                        'name'        => $l->name,
                        'fiscal_year' => $l->pivot->fiscal_year,
                    ])->values()->all(),
                    'usage'               => 0.0,
                    'applications'        => [],
                ];
            }

            $mapped[$identity->id]['usage'] += $app['usage'];
            $mapped[$identity->id]['applications'][] = $app;
        }

        $byUsage = fn ($a, $b) => [$b['usage'], $b['devices'] ?? 0, $a['name']] <=> [$a['usage'], $a['devices'] ?? 0, $b['name']];
        usort($unmapped, $byUsage);
        $mapped = array_values($mapped);
        usort($mapped, fn ($a, $b) => [$b['usage'], $a['name']] <=> [$a['usage'], $b['name']]);

        return [
            'fiscal_year'              => $fy,
            'mapped'                   => $mapped,
            'unmapped'                 => $unmapped,
            'unmapped_below_threshold' => $below,
        ];
    }

    private function matches(ProductIdentityAlias $alias, string $raw, string $normalized): bool
    {
        if ($alias->match_type === 'regex') {
            return (bool) @preg_match(ProductIdentityAlias::regexFor($alias->pattern), $raw);
        }

        $pattern = self::normalize($alias->pattern);
        if ($pattern === '') {
            return false;
        }

        return $alias->match_type === 'prefix'
            ? str_starts_with($normalized, $pattern)
            : $normalized === $pattern;
    }

    /** @return Collection<int, ProductIdentityAlias> */
    private function aliases(): Collection
    {
        return $this->aliases ??= ProductIdentityAlias::query()
            ->whereHas('productIdentity')
            ->with('productIdentity')
            ->get();
    }
}
