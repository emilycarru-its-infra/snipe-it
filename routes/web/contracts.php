<?php

use App\Http\Controllers\ContractReportsController;
use App\Http\Controllers\ContractsController;
use Illuminate\Support\Facades\Route;
use Tabuna\Breadcrumbs\Trail;

// Contracts. URL stays at /contracts (mounting under /licenses/contracts
// would collide with the Route::resource('licenses') binding). The
// sub-nav nesting under Licenses lives in layouts/default.blade.php.
//
// /contracts is one page: tiles, charts, the drill-down reports and the
// full register table. The reports below are the same sections the page
// embeds inline — each keeps a standalone URL so a single report can be
// linked, printed or pulled as CSV on its own.

$reportCrumb = function (string $routeName, string $titleKey) {
    return fn (Trail $trail) => $trail->parent('contracts.index')
        ->push(trans("admin/contracts/general.$titleKey"), route($routeName));
};

// Registered ahead of the resource: these are literal single-segment paths
// and `contracts/{contract}` would otherwise swallow every one of them.
Route::group(['middleware' => ['auth'], 'prefix' => 'contracts'], function () use ($reportCrumb) {
    Route::get('expiring-soon', [ContractReportsController::class, 'expiringSoon'])
        ->name('contracts.reports.expiring-soon')
        ->breadcrumbs($reportCrumb('contracts.reports.expiring-soon', 'report_expiring_soon_title'));
    Route::get('renewal-series', [ContractReportsController::class, 'renewalSeries'])
        ->name('contracts.reports.renewal-series')
        ->breadcrumbs($reportCrumb('contracts.reports.renewal-series', 'report_renewal_series_title'));
    Route::get('by-theme', [ContractReportsController::class, 'byTheme'])
        ->name('contracts.reports.by-theme')
        ->breadcrumbs($reportCrumb('contracts.reports.by-theme', 'report_by_theme_title'));
    Route::get('by-provider', [ContractReportsController::class, 'byProvider'])
        ->name('contracts.reports.by-provider')
        ->breadcrumbs($reportCrumb('contracts.reports.by-provider', 'report_by_provider_title'));
    Route::get('serial-register', [ContractReportsController::class, 'serialRegister'])
        ->name('contracts.reports.serial-register')
        ->breadcrumbs($reportCrumb('contracts.reports.serial-register', 'report_serial_register_title'));
    Route::get('naming-violators', [ContractReportsController::class, 'namingViolatorsReport'])
        ->name('contracts.reports.naming-violators')
        ->breadcrumbs($reportCrumb('contracts.reports.naming-violators', 'report_naming_violators_title'));
    Route::get('stale', [ContractReportsController::class, 'staleReport'])
        ->name('contracts.reports.stale')
        ->breadcrumbs($reportCrumb('contracts.reports.stale', 'report_stale_title'));
});

Route::resource('contracts', ContractsController::class, [
    'middleware' => ['auth'],
    'parameters' => ['contracts' => 'contract'],
]);

// The contracts dashboard used to be a second page under /reports. It was
// folded into /contracts; these keep old bookmarks and emailed links alive.
Route::group(['middleware' => ['auth'], 'prefix' => 'reports/contracts'], function () {
    $moved = [
        'expiring-soon'    => 'contracts.reports.expiring-soon',
        'umbrellas'        => 'contracts.reports.renewal-series',
        'by-theme'         => 'contracts.reports.by-theme',
        'by-provider'      => 'contracts.reports.by-provider',
        'serial-register'  => 'contracts.reports.serial-register',
        'naming-violators' => 'contracts.reports.naming-violators',
        'stale'            => 'contracts.reports.stale',
    ];

    Route::get('/', fn () => redirect()->route('contracts.index', request()->query(), 301))
        ->name('reports.contracts');

    foreach ($moved as $oldPath => $newRoute) {
        Route::get($oldPath, fn () => redirect()->route($newRoute, request()->query(), 301));
    }
});
