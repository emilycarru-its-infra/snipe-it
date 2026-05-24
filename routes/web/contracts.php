<?php

use App\Http\Controllers\ContractsController;
use Illuminate\Support\Facades\Route;

// Contracts. URL stays at /contracts (mounting under /licenses/contracts
// would collide with the Route::resource('licenses') binding). The
// sub-nav nesting under Licenses lives in layouts/default.blade.php.
Route::resource('contracts', ContractsController::class, [
    'middleware' => ['auth'],
    'parameters' => ['contracts' => 'contract'],
]);
