<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Route;

/**
 * Pages for a record are addressed by the identifier people use for it — an
 * order number, a serial, a username — instead of the database id, so a link
 * means something when it is read and survives an export and re-import.
 *
 * Only route parameters bound to the model use it (App\Routing\StableRouteKeys
 * marks them with the `route_key` field); everything else keeps the id. A value
 * made only of digits is always an id, which keeps every older link working and
 * is why a purely numeric identifier is never used. A value is also skipped when
 * another record shares it or it would collide with a fixed path such as
 * `create`, and then the next identifier, or the id, is used instead.
 */
trait HasStableRouteKey
{
    /**
     * Columns to try, most preferred first.
     *
     * @return array<int, string>
     */
    abstract public function stableRouteKeyColumns(): array;

    /**
     * The identifier this record's pages use: the first usable column value,
     * otherwise the id.
     */
    public function getRouteKeyAttribute(): string
    {
        foreach ($this->stableRouteKeyColumns() as $column) {
            $value = trim((string) $this->getAttribute($column));
            if (static::usableRouteKey($value) && ! static::sharedRouteKey($column, $value)) {
                return $value;
            }
        }

        return (string) $this->getKey();
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->resolveStableRouteBinding($this->newQuery(), $value, $field);
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null)
    {
        $query = in_array(SoftDeletes::class, class_uses_recursive(static::class), true)
            ? $this->newQuery()->withTrashed()
            : $this->newQuery();

        return $this->resolveStableRouteBinding($query, $value, $field);
    }

    protected function resolveStableRouteBinding(Builder $query, $value, $field)
    {
        if ($field !== null && $field !== 'route_key') {
            return $query->where($field, $value)->first();
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return $query->whereKey($value)->first();
        }

        foreach ($this->stableRouteKeyColumns() as $column) {
            $matches = (clone $query)->where($column, $value)->limit(2)->get();
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    /**
     * Safe in a path, not all digits, and not a fixed path segment.
     */
    public static function usableRouteKey(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 191
            && ! ctype_digit($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._@+-]*$/', $value) === 1
            && ! isset(static::fixedPathSegments()[strtolower($value)]);
    }

    /**
     * Whether another record, deleted ones included, has the same value. One
     * query per column per request finds every shared value at once.
     */
    protected static function sharedRouteKey(string $column, string $value): bool
    {
        $request = app('request');
        $cacheKey = 'stable-route-keys.'.static::class.'.'.$column;
        $shared = $request->attributes->get($cacheKey);

        if ($shared === null) {
            $shared = array_flip(array_map('strval', static::query()->withoutGlobalScopes()
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->pluck($column)
                ->all()));
            $request->attributes->set($cacheKey, $shared);
        }

        return isset($shared[$value]);
    }

    /**
     * Every literal path segment the app routes, such as `create` or `bulk`,
     * which an identifier must never shadow.
     *
     * @return array<string, true>
     */
    protected static function fixedPathSegments(): array
    {
        static $segments = null;

        if ($segments === null) {
            $segments = [];
            foreach (Route::getRoutes()->getRoutes() as $route) {
                foreach (explode('/', $route->uri()) as $segment) {
                    if ($segment !== '' && ! str_contains($segment, '{')) {
                        $segments[strtolower($segment)] = true;
                    }
                }
            }
        }

        return $segments;
    }
}
