<?php

namespace Tests\Feature\Smoke;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Crawls every UI GET route as a superuser against a seeded dataset and
 * asserts none return a 5xx. This is the data-shape safety net: it catches
 * controller/Blade regressions that only surface when a page is actually
 * rendered with real records (null relations, bad casts, stale column
 * names) — the class of "click a button, get a 500" bug that unit tests
 * over empty tables miss.
 *
 * Routes whose parameters we can't seed are skipped (and counted, so the
 * coverage is visible rather than silently shrinking). Downloads, binaries
 * and side-effecting GETs are denylisted.
 */
class RouteSmokeTest extends TestCase
{
    /**
     * URI substrings we never crawl: downloads, binaries, side-effects, non-HTML.
     * These are matched anywhere in the path, so keep them specific — a loose
     * needle like "health" would also swallow "reports/fleet-health".
     */
    private array $denyContains = [
        'export', 'download', 'backup', 'barcode', 'qr_code', '/label', 'labels/',
        'logout', 'telescope', 'debugbar', 'livewire', 'saml', 'oauth',
        '.json', 'restore', '/file/', '/files/', 'purge', 'stream',
    ];

    /**
     * Exact URIs to skip — operational endpoints and known test-env artifacts.
     * Matched against the full route URI so they can't over-match (e.g. the
     * health-check endpoint without catching the fleet-health dashboard).
     */
    private array $denyExact = [
        'health',
        // phpinfo() under CLI/PHPUnit doesn't emit the <body> HTML the blade's
        // regex expects, so the page 500s here but renders fine under prod FPM.
        'admin/phpinfo',
    ];

    public function test_no_ui_get_route_returns_a_server_error(): void
    {
        $map = $this->seedParamMap();
        $admin = User::factory()->superuser()->create();

        $failures = [];
        $hit = 0;
        $obBaseline = ob_get_level();

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = $route->uri();
            if (str_starts_with($uri, 'api/') || str_starts_with($uri, '_') || $this->denied($uri)) {
                continue;
            }

            $params = [];
            $fillable = true;
            foreach ($route->parameterNames() as $name) {
                if (array_key_exists($name, $map)) {
                    $params[$name] = $map[$name];
                } elseif (str_contains($uri, '{'.$name.'?}')) {
                    // optional parameter we can't fill — just omit it
                    continue;
                } else {
                    $fillable = false;
                    break;
                }
            }
            if (! $fillable || ! $route->getName()) {
                continue;
            }

            try {
                $url = route($route->getName(), $params, false);
            } catch (\Throwable $e) {
                continue;
            }

            $hit++;
            try {
                $status = $this->actingAs($admin)->get($url)->getStatusCode();
                if ($status >= 500) {
                    $failures[] = sprintf('%s  [%s]  -> %d', $url, $route->getName(), $status);
                }
            } catch (\Throwable $e) {
                // A route that throws instead of returning a 5xx is just as
                // broken — record it with the route and exception.
                $failures[] = sprintf('%s  [%s]  threw %s: %s', $url, $route->getName(), $e::class, $e->getMessage());
            } finally {
                // Streamed/download responses invoke their callback in-process
                // and can leave orphaned output buffers; drain them so PHPUnit
                // doesn't flag the test risky.
                while (ob_get_level() > $obBaseline) {
                    ob_end_clean();
                }
            }
        }

        $this->assertSame([], $failures, "UI GET routes returned 5xx:\n".implode("\n", $failures));

        // Coverage floor: if a future change breaks the seed map, the crawl
        // would skip most routes and still pass with zero 5xx — false
        // confidence. Assert we actually exercised the bulk of the UI.
        $this->assertGreaterThan(200, $hit, "Route smoke crawl only covered {$hit} routes — the seed map may be broken.");
    }

    private function denied(string $uri): bool
    {
        if (in_array($uri, $this->denyExact, true)) {
            return true;
        }

        foreach ($this->denyContains as $needle) {
            if (str_contains($uri, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seed one record per major entity and map every route-parameter name
     * that refers to it. Each seed is best-effort: a factory that can't
     * build just leaves its routes to be skipped, never fails the crawl.
     *
     * @return array<string, int|string>
     */
    private function seedParamMap(): array
    {
        $map = ['catalog' => 'statuses', 'key' => 'checkout.asset'];

        $seed = function (array $names, callable $factory) use (&$map) {
            try {
                $id = $factory();
                foreach ($names as $n) {
                    $map[$n] = $id;
                }
            } catch (\Throwable $e) {
                // leave unseeded → routes needing it are skipped
            }
        };

        $seed(['asset', 'assetId'], fn () => \App\Models\Asset::factory()->create()->id);
        $seed(['consumable', 'consumablesID', 'consumable_id'], fn () => \App\Models\Consumable::factory()->create()->id);
        $seed(['accessory', 'accessoryID', 'accessory_id'], fn () => \App\Models\Accessory::factory()->create()->id);
        $seed(['component', 'componentID'], fn () => \App\Models\Component::factory()->create()->id);
        $seed(['license', 'licenseId', 'license_id'], fn () => \App\Models\License::factory()->create()->id);
        // licenseModel is a LicenseModel (a license SKU), NOT a License — map it
        // to a real one so the crawl exercises /license-models/{id} instead of
        // binding a License id onto an unrelated LicenseModel.
        $seed(['licenseModel'], fn () => \App\Models\LicenseModel::create(['name' => 'Smoke License Model', 'type_code' => 'SMOKE-LM'])->id);
        $seed(['model', 'modelId', 'model_id'], fn () => \App\Models\AssetModel::factory()->create()->id);
        $seed(['user', 'userId'], fn () => \App\Models\User::factory()->create()->id);
        $seed(['category'], fn () => \App\Models\Category::factory()->create()->id);
        $seed(['manufacturer'], fn () => \App\Models\Manufacturer::factory()->create()->id);
        $seed(['supplier'], fn () => \App\Models\Supplier::factory()->create()->id);
        $seed(['location', 'locationId'], fn () => \App\Models\Location::factory()->create()->id);
        $seed(['company'], fn () => \App\Models\Company::factory()->create()->id);
        $seed(['statuslabel'], fn () => \App\Models\Statuslabel::factory()->create()->id);
        $seed(['department'], fn () => \App\Models\Department::factory()->create()->id);
        $seed(['group'], fn () => \App\Models\Group::factory()->create()->id);
        $seed(['fieldset'], fn () => \App\Models\CustomFieldset::factory()->create()->id);
        $seed(['depreciation'], fn () => \App\Models\Depreciation::factory()->create()->id);
        $seed(['contract'], fn () => \App\Models\Contract::factory()->create()->id);
        $seed(['order'], fn () => \App\Models\Order::factory()->create()->id);
        $seed(['purchase_order'], fn () => \App\Models\PurchaseOrder::factory()->create()->id);
        $seed(['reportTemplate'], fn () => \App\Models\ReportTemplate::factory()->create()->id);
        $seed(['lease_decision'], fn () => \App\Models\LeaseDecision::factory()->create()->id);
        $seed(['maintenance'], fn () => \App\Models\Maintenance::factory()->create()->id);
        $seed(['kit'], fn () => \App\Models\PredefinedKit::factory()->create()->id);
        $seed(['exhibitProject'], fn () => \App\Models\ExhibitProject::create([
            'student_name' => 'Smoke Student',
            'year' => 2026,
            'exhibit_id' => \App\Models\Exhibit::firstOrCreate(['name' => 'Grad Show'])->id,
        ])->id);

        return $map;
    }
}
