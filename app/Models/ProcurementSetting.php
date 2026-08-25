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

    public static function get(string $key, ?string $default = null): ?string
    {
        // Cached per request: the cadence is asked for on every render of
        // the queue, and it cannot change mid-request.
        static $cache = [];

        if (! array_key_exists($key, $cache)) {
            $cache[$key] = static::query()->where('key', $key)->value('value');
        }

        return $cache[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
