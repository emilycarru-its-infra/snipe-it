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

    // Inline quantity nudge from the toner dashboard / info-panel stepper.
    // Adjusts qty by a delta (or sets an absolute value) and returns JSON,
    // routing through the same Eloquent save the edit form uses so the
    // change lands in the consumable's activity log (who + when + old→new).
    Route::post('{consumable}/adjust-qty',
        [Consumables\ConsumablesController::class, 'adjustQuantity']
    )->name('consumables.adjust-qty');

    // Stepper up arrow: orders to cite when restocking (every restock must be
    // tied to a real order from the Orders module).
    Route::get('{consumable}/restock-orders',
        [Consumables\ConsumablesController::class, 'ordersForRestock']
    )->name('consumables.restock-orders');

    // Stepper down arrow: record a cartridge as used by a printer. The
    // picker lists compatible printers; consume checks out 1 + a GL line.
    Route::get('{consumable}/compatible-printers',
        [Consumables\ConsumablesController::class, 'compatiblePrinters']
    )->name('consumables.compatible-printers');

    Route::post('{consumable}/consume',
        [Consumables\ConsumablesController::class, 'consume']
    )->name('consumables.consume');

    // Add the consumable to an existing planned order, or create a new one.
    Route::get(
        '{consumable}/order',
        [Consumables\ConsumableOrderController::class, 'create']
    )->name('consumables.order.create');

    Route::post(
        '{consumable}/order',
        [Consumables\ConsumableOrderController::class, 'store']
    )->name('consumables.order.store');

    // Transactions (journal-transfer lines): export, hand-create, after-the-fact edit.
    Route::get(
        '{consumable}/transactions/export',
        [Consumables\ConsumableTransactionController::class, 'export']
    )->name('consumables.transactions.export');

    Route::get(
        '{consumable}/transactions/create',
        [Consumables\ConsumableTransactionController::class, 'create']
    )->name('consumables.transactions.create');

    Route::post(
        '{consumable}/transactions',
        [Consumables\ConsumableTransactionController::class, 'store']
    )->name('consumables.transactions.store');

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
