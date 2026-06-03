<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class OptimizeTest extends TestCase
{
    public function test_optimize_succeeds()
    {
        // `optimize` writes config/route caches into bootstrap/cache, which
        // every parallel worker shares. Caching (then clearing) it mid-run
        // races sibling processes' app boots — they hit
        // require(bootstrap/cache/routes-vN.php) on a file this test just
        // deleted. The smoke test is only meaningful single-process anyway.
        if (getenv('LARAVEL_PARALLEL_TESTING')) {
            $this->markTestSkipped('optimize mutates the shared bootstrap/cache; unsafe under parallel testing.');
        }

        $this->beforeApplicationDestroyed(function () {
            $this->artisan('config:clear');
            $this->artisan('route:clear');
            $this->artisan('view:clear');
        });

        $this->artisan('optimize')->assertSuccessful();
    }
}
