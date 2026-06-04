<?php

namespace Tests\Unit;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * The Settings → Emails preview is shown in a same-origin iframe, so its
 * response must be framable by us (SAMEORIGIN) while every other route keeps
 * the default X-Frame-Options: DENY. The feature suite disables SecurityHeaders
 * globally, so we exercise the middleware directly here.
 */
class SecurityHeadersFramingTest extends TestCase
{
    private function frameOptionForRoute(?string $routeName): ?string
    {
        $request = Request::create('/whatever', 'GET');
        if ($routeName !== null) {
            $route = (new Route(['GET'], '/whatever', []))->name($routeName);
            $request->setRouteResolver(fn () => $route);
        }

        $response = (new SecurityHeaders)->handle($request, fn () => new Response('ok'));

        return $response->headers->get('X-Frame-Options');
    }

    public function test_email_preview_route_is_framable_sameorigin(): void
    {
        $this->assertSame('SAMEORIGIN', $this->frameOptionForRoute('settings.emails.preview'));
    }

    public function test_other_routes_stay_deny(): void
    {
        $this->assertSame('DENY', $this->frameOptionForRoute('settings.index'));
    }
}
