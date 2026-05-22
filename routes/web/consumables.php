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

    // Add the consumable to an existing planned order, or create a new one.
    Route::get(
        '{consumable}/order',
        [Consumables\ConsumableOrderController::class, 'create']
    )->name('consumables.order.create');

    Route::post(
        '{consumable}/order',
        [Consumables\ConsumableOrderController::class, 'store']
    )->name('consumables.order.store');

    // After-the-fact maintenance of GL transactions (journal-transfer lines).
    Route::get(
        '{consumable}/transactions/{transaction}/edit',
        [Consumables\ConsumableTransactionController::class, 'edit']
    )->name('consumables.transactions.edit');

    Route::put(
        '{consumable}/transactions/{transaction}',
        [Consumables\ConsumableTransactionController::class, 'update']
    )->name('consumables.transactions.update');

    Route::delete(
        '{consumable}/transactions/{transaction}',
        [Consumables\ConsumableTransactionController::class, 'destroy']
    )->name('consumables.transactions.void');

});
    
Route::resource('consumables', Consumables\ConsumablesController::class, [
    'middleware' => ['auth'],
    'parameters' => ['consumable' => 'consumable_id'],
]);
