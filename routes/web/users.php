<?php

use App\Http\Controllers\ImpersonateController;
use App\Http\Controllers\Users;
use Illuminate\Support\Facades\Route;
use Tabuna\Breadcrumbs\Trail;

// View-as. Starting is superuser-gated inside the controller rather than by
// middleware, so the refusals can say *why* — you cannot borrow a superuser,
// yourself, or a deactivated account.
Route::post('users/{user}/impersonate', [ImpersonateController::class, 'start'])
    ->middleware('auth')
    ->name('users.impersonate');

// Stopping is deliberately outside any authorization: the borrowed identity
// has fewer rights than the real one by design, so gating the exit on
// anything the target lacks would strand the session with no way back.
Route::post('impersonate/stop', [ImpersonateController::class, 'stop'])
    ->middleware('auth')
    ->name('impersonate.stop');

// User Management

Route::group(['prefix' => 'users', 'middleware' => ['auth']], function () {

    Route::get(
        'ldap',
        [
            Users\LDAPImportController::class,
            'create',
        ]
    )->name('ldap/user')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('users.index')
            ->push(trans('general.ldap_user_sync'), route('ldap/user')));

    Route::post(
        'ldap',
        [
            Users\LDAPImportController::class,
            'store',
        ]
    );

    Route::get(
        'export',
        [
            Users\UsersController::class,
            'getExportUserCsv',
        ]
    )->name('users.export');

    Route::get(
        '{user}/clone',
        [
            Users\UsersController::class,
            'getClone',
        ]
    )->name('users.clone.show')->withTrashed();

    Route::post(
        '{user}/clone',
        [
            Users\UsersController::class,
            'postCreate',
        ]
    )->name('users.clone.store')->withTrashed();

    Route::post(
        '{user}/restore',
        [
            Users\UsersController::class,
            'getRestore',
        ]
    )->name('users.restore.store')->withTrashed();

    Route::post(
        '{userId}/password',
        [
            Users\UsersController::class,
            'sendPasswordReset',
        ]
    )->name('users.password');

    Route::get(
        '{userId}/print',
        [
            Users\UsersController::class,
            'printInventory',
        ]
    )->name('users.print');

    Route::post(
        '{userId}/email',
        [
            Users\UsersController::class,
            'emailAssetList',
        ]
    )->name('users.email');

    Route::post(
        '{user}/acceptance-reminder',
        [
            Users\UsersController::class,
            'resendAcceptanceReminder',
        ]
    )->name('users.acceptance_reminder')->withTrashed();

    Route::post(
        'bulkedit',
        [
            Users\BulkUsersController::class,
            'edit',
        ]
    )->name('users/bulkedit')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('users.index')
            ->push(trans('general.bulk_checkin_delete'), route('users.index')));

    Route::post(
        'merge',
        [
            Users\BulkUsersController::class,
            'merge',
        ]
    )->name('users.merge.save');

    Route::post(
        'bulksave',
        [
            Users\BulkUsersController::class,
            'destroy',
        ]
    )->name('users/bulksave');

    Route::post(
        'bulkeditsave',
        [
            Users\BulkUsersController::class,
            'update',
        ]
    )->name('users/bulkeditsave');

});

Route::resource('users', Users\UsersController::class, [
    'middleware' => ['auth'],
])->withTrashed();
