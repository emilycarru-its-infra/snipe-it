<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
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
            if (static::usableRouteKey($value) && ! static::sharedRouteKey($value)) {
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

        // One match across every key column, or none: a serial that equals
        // another device's asset tag must not open either of them.
        $matches = (clone $query)->where(function (Builder $where) use ($value) {
            foreach ($this->stableRouteKeyColumns() as $column) {
                $where->orWhere($column, $value);
            }
        })->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Safe in a path, not all digits, and not a fixed path segment. An `@` is
     * refused so an email-style username never ends up in a URL or a log.
     */
    public static function usableRouteKey(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 191
            && ! ctype_digit($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/', $value) === 1
            && ! isset(static::fixedPathSegments()[strtolower($value)]);
    }

    /**
     * Whether the value appears more than once across all the key columns,
     * deleted records included, so it could not name one record. One query
     * per request finds every such value at once.
     */
    protected static function sharedRouteKey(string $value): bool
    {
        $request = app('request');
        $cacheKey = 'stable-route-keys.'.static::class;
        $shared = $request->attributes->get($cacheKey);

        if ($shared === null) {
            $model = new static;
            $values = null;
            foreach ($model->stableRouteKeyColumns() as $column) {
                $select = static::query()->withoutGlobalScopes()->toBase()
                    ->select($column.' as route_key_value')
                    ->whereNotNull($column)
                    ->where($column, '!=', '');
                $values = $values === null ? $select : $values->unionAll($select);
            }

            $shared = array_flip(array_map('strval', DB::connection($model->getConnectionName())
                ->query()
                ->fromSub($values, 'route_keys')
                ->groupBy('route_key_value')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('route_key_value')
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
