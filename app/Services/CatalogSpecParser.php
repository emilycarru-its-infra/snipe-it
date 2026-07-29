<?php

namespace App\Services;

/**
 * Turns a price-list display name ("MacBook Pro | 14" | M5 Pro | 24GB |
 * 1TB | Black | Nano-texture") into structured attributes the storefront
 * configurator can filter on. The names come from our own import cleaner,
 * but human-keyed quirks survive in the wild — lowercase-L separators,
 * doubled spaces, sizes glued to the family token — so parsing is
 * tolerant rather than strict.
 *
 * Chip core counts are the base configuration for that chip as published
 * on apple.com — a CTO row binned differently gets corrected when the
 * Apple store sync recognises its part number.
 */
class CatalogSpecParser
{
    /**
     * Base CPU/GPU/Neural Engine configuration per Apple chip, verified
     * against apple.com/ca product selection data (July 2026).
     */
    public const CHIP_SPECS = [
        'M5' => ['10-core CPU', '10-core GPU', '16-core Neural Engine'],
        'M5 Pro' => ['15-core CPU', '16-core GPU', '16-core Neural Engine'],
        'M5 Max' => ['18-core CPU', '32-core GPU', '16-core Neural Engine'],
        'M4' => ['10-core CPU', '10-core GPU', '16-core Neural Engine'],
        'M4 Pro' => ['12-core CPU', '16-core GPU', '16-core Neural Engine'],
        'M4 Max' => ['14-core CPU', '32-core GPU', '16-core Neural Engine'],
        'M3 Ultra' => ['28-core CPU', '60-core GPU', '32-core Neural Engine'],
    ];

    /**
     * A PC price list names its processor and graphics the way the maker
     * markets them, with no common shape between them: "Ryzen 7 PRO 8700G",
     * "Ultra 9", a bare Threadripper part code like "5955WX". Recognising
     * them by vocabulary is what keeps a CPU out of the leftovers bucket,
     * where it read as "Also: 5955WX".
     */
    private const GPU_PATTERN = '/\b(rtx|gtx|geforce|radeon|quadro|nvidia)\b/i';

    private const CPU_PATTERN = '/\b(ryzen|xeon|threadripper|snapdragon|pentium|celeron|arm|intel)\b/i';

    /** "Core i7", "Ultra 9" — a tier named ahead of its number. */
    private const CPU_TIER_PATTERN = '/^(core\s+i\d|ultra\s+\d)/i';

    /** A bare workstation part code: 5955WX, 7965WX. */
    private const CPU_CODE_PATTERN = '/^\d{4}[a-z]{2}$/i';

    private const COLORS = [
        'silver', 'black', 'space black', 'gray', 'grey', 'space gray', 'space grey',
        'blue', 'pink', 'purple', 'yellow', 'orange', 'green', 'starlight', 'midnight',
    ];

    /**
     * @return array{family: ?string, screen_size: ?string, chip: ?string,
     *               spec_cpu: ?string, spec_gpu: ?string, spec_npu: ?string,
     *               ram_gb: ?int, storage: ?string, color: ?string,
     *               display_finish: ?string, extras: ?string}
     */
    public function parse(string $name): array
    {
        $out = [
            'family' => null, 'screen_size' => null, 'chip' => null,
            'spec_cpu' => null, 'spec_gpu' => null, 'spec_npu' => null,
            'ram_gb' => null, 'storage' => null, 'color' => null,
            'display_finish' => null, 'extras' => null,
        ];

        // Split on pipes — including the lowercase L some rows use as one.
        $tokens = preg_split('/\s*(?:\||\sl\s)\s*/u', trim($name));
        $tokens = array_values(array_filter(array_map(
            fn ($t) => trim(preg_replace('/\s+/', ' ', $t)),
            $tokens
        )));

        $capacities = [];
        $extras = [];

        foreach ($tokens as $i => $token) {
            // Family is the first token; a size glued onto it splits off.
            if ($i === 0) {
                if (preg_match('/^(.*?)\s*(\d+(?:\.\d+)?)"$/', $token, $m)) {
                    $out['screen_size'] = $m[2];
                    $token = trim($m[1]);
                }
                $out['family'] = preg_replace('/^Apple\s+/i', '', $token);

                continue;
            }

            if (preg_match('/^(\d+(?:\.\d+)?)"$/', $token, $m)) {
                $out['screen_size'] = $m[1];

                continue;
            }

            if (preg_match('/^M\d(?:\s+(Pro|Max|Ultra))?$/i', $token)) {
                $out['chip'] = self::normalizeChip($token);

                continue;
            }

            if (preg_match('/^(\d+)\s*(GB|TB)$/i', $token, $m)) {
                $capacities[] = ['value' => (int) $m[1], 'unit' => strtoupper($m[2])];

                continue;
            }

            if (preg_match('/nano.?texture/i', $token)) {
                $out['display_finish'] = 'nano';

                continue;
            }

            if (preg_match('/standard\s+glass/i', $token)) {
                $out['display_finish'] = 'standard';

                continue;
            }

            if (in_array(strtolower($token), self::COLORS, true)) {
                $out['color'] = self::normalizeColor($token);

                continue;
            }

            // Graphics before processor: a discrete card names a number
            // ("RTX 4000 ADA 20GB") that no CPU vocabulary would claim.
            if ($out['spec_gpu'] === null && preg_match(self::GPU_PATTERN, $token)) {
                $out['spec_gpu'] = $token;

                continue;
            }

            if ($out['spec_cpu'] === null && (preg_match(self::CPU_PATTERN, $token)
                || preg_match(self::CPU_TIER_PATTERN, $token)
                || preg_match(self::CPU_CODE_PATTERN, $token))) {
                $out['spec_cpu'] = $token;

                continue;
            }

            $extras[] = $token;
        }

        // Two capacities read RAM then storage; a lone one is storage —
        // tablets list only their storage, never their RAM.
        if (count($capacities) >= 2) {
            $out['ram_gb'] = $capacities[0]['unit'] === 'GB' ? $capacities[0]['value'] : null;
            $out['storage'] = $capacities[1]['value'].$capacities[1]['unit'];
        } elseif (count($capacities) === 1) {
            $out['storage'] = $capacities[0]['value'].$capacities[0]['unit'];
        }

        if ($out['chip'] && isset(self::CHIP_SPECS[$out['chip']])) {
            [$out['spec_cpu'], $out['spec_gpu'], $out['spec_npu']] = self::CHIP_SPECS[$out['chip']];
        }

        $out['extras'] = $extras ? implode(' · ', $extras) : null;

        return $out;
    }

    public static function normalizeChip(string $chip): string
    {
        $chip = preg_replace('/\s+/', ' ', trim($chip));

        return preg_replace_callback(
            '/^(m\d)(?:\s+(pro|max|ultra))?$/i',
            fn ($m) => strtoupper($m[1]).(isset($m[2]) ? ' '.ucfirst(strtolower($m[2])) : ''),
            $chip
        );
    }

    public static function normalizeColor(string $color): string
    {
        $color = ucwords(strtolower(trim($color)));

        return str_replace('Grey', 'Gray', $color);
    }
}
