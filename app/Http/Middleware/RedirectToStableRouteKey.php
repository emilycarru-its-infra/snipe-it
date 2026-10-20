<?php

namespace App\Http\Middleware;

use App\Models\PurchaseOrder;
use App\Models\Traits\HasStableRouteKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Forwards a page opened by its database id — an old bookmark, an email already
 * sent, a table that builds links from row ids — to the same page under the
 * record's stable identifier, so the address bar always shows the link worth
 * copying. Reads only: a form posted to an id URL is handled where it lands.
 * Only someone allowed to view the record is forwarded, so the redirect never
 * reveals a serial or username to anyone the page itself would refuse.
 */
class RedirectToStableRouteKey
{
    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();

        if (! $route || ! $route->getName() || ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        foreach ($route->parameters() as $name => $value) {
            $canonical = match (true) {
                $value instanceof PurchaseOrder => (string) ($value->po_number ?: $value->getKey()),
                is_object($value) && $route->bindingFieldFor($name) === 'route_key'
                    && in_array(HasStableRouteKey::class, class_uses_recursive($value), true) => $value->getRouteKeyAttribute(),
                default => null,
            };

            if ($canonical !== null && (string) $route->originalParameter($name) !== $canonical
                && Gate::allows('view', $value)) {
                $url = route($route->getName(), $route->parameters());
                $query = $request->getQueryString();

                return redirect()->to($query ? $url.'?'.$query : $url, 302);
            }
        }

        return $next($request);
    }
}
