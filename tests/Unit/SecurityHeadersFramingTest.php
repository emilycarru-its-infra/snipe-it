<?php

namespace Tests\Unit;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * Same-origin framing is a designed feature — the Settings → Emails hub
 * previews mail in an iframe and the record lightbox frames /hardware and
 * /users pages — so every route answers X-Frame-Options: SAMEORIGIN. That
 * still refuses all cross-origin framing, which is the clickjacking
 * boundary that matters. The feature suite disables SecurityHeaders
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

    public function test_record_routes_are_framable_by_the_lightbox(): void
    {
        $this->assertSame('SAMEORIGIN', $this->frameOptionForRoute('hardware.show'));
        $this->assertSame('SAMEORIGIN', $this->frameOptionForRoute('users.show'));
    }

    public function test_routes_are_never_framable_cross_origin(): void
    {
        // SAMEORIGIN everywhere: our pages may frame our pages; no other
        // origin may frame anything.
        $this->assertSame('SAMEORIGIN', $this->frameOptionForRoute('settings.index'));
        $this->assertSame('SAMEORIGIN', $this->frameOptionForRoute(null));
    }
}
