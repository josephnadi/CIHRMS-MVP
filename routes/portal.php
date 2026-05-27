<?php

declare(strict_types=1);

use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\FeesController;
use App\Http\Controllers\Portal\LoginController;
use App\Http\Controllers\Portal\ProfileController;
use App\Http\Controllers\Portal\StatementsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Member self-service portal (M2)
|--------------------------------------------------------------------------
|
| Routes here use the `member` guard (separate from staff `web` guard).
| Deliberately do NOT mount the staff `RequireTwoFactor` middleware —
| members aren't part of the staff 2FA programme. Throttling for the
| login endpoint mirrors the staff pattern (5 / IP / minute) plus a
| broader per-email global guard delegated to LoginController.
|
*/

Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware('guest:member')->group(function () {
        Route::get('/login',    [LoginController::class, 'create'])->name('login');
        Route::post('/login',   [LoginController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('login.store');
    });

    Route::middleware('auth:member')->group(function () {
        Route::post('/logout',  [LoginController::class, 'destroy'])->name('logout');
        Route::get('/',         [DashboardController::class, 'index'])->name('home');
        Route::get('/fees',     [FeesController::class,      'index'])->name('fees');
        Route::post('/fees/{invoice}/pay',
                                [FeesController::class,      'pay'])->name('fees.pay');
        Route::get('/statements', [StatementsController::class, 'index'])->name('statements');
        Route::get('/profile',  [ProfileController::class,   'edit'])->name('profile');
        Route::patch('/profile',[ProfileController::class,   'update'])->name('profile.update');
    });
});
