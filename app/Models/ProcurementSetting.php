<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Small procurement facts that change without code: the lease master
 * contract, and the anchor that fixes which schedule pair is open for
 * ordering this quarter.
 *
 * Deliberately a plain key/value table. These are a handful of values a
 * person edits once or twice a year, and giving each one a column would
 * mean a migration every time a new one appears — which is the problem
 * being solved, not a shape to repeat.
 */
class ProcurementSetting extends Model
{
    protected $table = 'procurement_settings';

    protected $fillable = ['key', 'value'];

    /** @var array<string, string|null> */
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        // Cached because the cadence is asked for on every render of the
        // queue. Writes clear it: a static cache that a write cannot reach
        // reports the old value for the rest of the process, which is how a
        // saved anchor appeared not to save.
        if (! array_key_exists($key, self::$cache)) {
            self::$cache[$key] = static::query()->where('key', $key)->value('value');
        }

        return self::$cache[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);

        self::$cache[$key] = $value;
    }

    /** Forget everything cached — between tests, and after a bulk edit. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
