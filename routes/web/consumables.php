<?php

use App\Http\Controllers\Consumables;
use Illuminate\Support\Facades\Route;



Route::group(['prefix' => 'consumables', 'middleware' => ['auth']], function () {
    Route::get(
        '{consumablesID}/checkout',
        [Consumables\ConsumableCheckoutController::class, 'create']
    )->name('consumables.checkout.show');

    Route::post(
        '{consumablesID}/checkout',
        [Consumables\ConsumableCheckoutController::class, 'store']
    )->name('consumables.checkout.store');


    Route::get('{consumable}/clone',
        [Consumables\ConsumablesController::class, 'clone']
    )->name('consumables.clone.create');

    // Inline quantity nudge from the consumable detail info-panel stepper.
    // Adjusts qty by a delta (or sets an absolute value) and returns JSON,
    // routing through the same Eloquent save the edit form uses so the
    // change lands in the consumable's activity log (who + when + old->new).
    Route::post('{consumable}/adjust-qty',
        [Consumables\ConsumablesController::class, 'adjustQuantity']
    )->name('consumables.adjust-qty');
    

});
    
Route::resource('consumables', Consumables\ConsumablesController::class, [
    'middleware' => ['auth'],
    'parameters' => ['consumable' => 'consumable_id'],
]);
