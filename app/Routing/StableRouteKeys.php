<?php

namespace App\Routing;

use App\Models\Traits\HasStableRouteKey;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Reflector;
use Illuminate\Support\Str;

/**
 * Marks each web route parameter bound to a model with a stable identifier, so
 * route() writes the order number into an order's URL rather than its database
 * id, and the binding reads it back.
 *
 * Only the first bound model on a route is marked: a later one keeps its id,
 * because Laravel scopes a marked child parameter to its parent's relation.
 * API routes keep ids, because integrations call them by id.
 */
class StableRouteKeys
{
    public static function apply(RouteCollectionInterface $routes): void
    {
        foreach ($routes->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/') || ! $route->parameterNames()) {
                continue;
            }

            $bound = [];
            foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
                $bound[$parameter->getName()] = Reflector::getParameterClassName($parameter);
            }
            if (! $bound) {
                continue;
            }

            foreach ($route->parameterNames() as $name) {
                $class = $bound[$name] ?? $bound[Str::camel($name)] ?? null;
                if ($class === null) {
                    continue;
                }
                if (in_array(HasStableRouteKey::class, class_uses_recursive($class), true)
                    && $route->bindingFieldFor($name) === null) {
                    $route->setBindingFields([$name => 'route_key'] + $route->bindingFields());
                }
                break;
            }
        }
    }

    /**
     * The identifier a record's page uses, for list tables that build links in
     * the browser. Null for records that only have an id, so the table keeps it.
     */
    public static function keyOf(mixed $model): ?string
    {
        if (! is_object($model) || ! in_array(HasStableRouteKey::class, class_uses_recursive($model), true)) {
            return null;
        }

        return $model->route_key;
    }
}
