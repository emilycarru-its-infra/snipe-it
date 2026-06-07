<?php

use App\Http\Controllers\AssetModelsController;
use App\Http\Controllers\BulkAssetModelsController;
use Illuminate\Support\Facades\Route;
use Tabuna\Breadcrumbs\Trail;

// Asset Model Management


Route::group(['prefix' => 'models', 'middleware' => ['auth']], function () {

    Route::get(
        '{model}/clone',
        [
            AssetModelsController::class, 
            'getClone'
        ]
    )->name('models.clone.create')->withTrashed()
        ->breadcrumbs(fn (Trail $trail) =>
        $trail->parent('models.index')
            ->push(trans('admin/models/table.clone'), route('models.index')));

    Route::post(
        '{model}/clone',
        [
            AssetModelsController::class, 
            'postCreate'
        ]
    )->name('models.clone.store')->withTrashed();

    // Legacy detail URL — the old getView action was modernised to the
    // resourceful show(). Redirect so old links/bookmarks keep working
    // instead of 500-ing on a missing controller method.
    Route::get(
        '{modelId}/view',
        fn ($modelId) => redirect()->route('models.show', $modelId)
    )->name('view/model');

    Route::post(
        '{modelID}/restore',
        [
            AssetModelsController::class, 
            'getRestore'
        ]
    )->name('models.restore.store');

    Route::get(
        '{modelId}/custom_fields',
        [
            AssetModelsController::class, 
            'getCustomFields'
        ]
    )->name('custom_fields/model');

    Route::post(
        'bulkedit',
        [
            BulkAssetModelsController::class, 
            'edit'
        ]
    )->name('models.bulkedit.index')
    ->breadcrumbs(fn (Trail $trail) =>
    $trail->parent('models.index')
        ->push(trans('general.bulk_edit'), route('models.index')));

    Route::post(
        'bulksave',
        [
            BulkAssetModelsController::class, 
            'update'
        ]
    )->name('models.bulkedit.store');

    Route::post(
        'bulkdelete',
        [
            BulkAssetModelsController::class, 
            'destroy'
        ]
    )->name('models.bulkdelete.store');



    // Drag-drop reorder endpoint for the toner dashboard's printer grid.
    // Accepts {ids:[...]} and writes display_order positionally.
    Route::post('reorder', [AssetModelsController::class, 'reorder'])
        ->name('models.reorder');

});

Route::resource('models', AssetModelsController::class, [
    'middleware' => ['auth'],
])->withTrashed();
