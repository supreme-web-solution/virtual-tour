<?php

use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/api', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('settings/api', [ApiTokenController::class, 'store'])->middleware('throttle:10,1')->name('api-tokens.store');
    Route::delete('settings/api/{token}', [ApiTokenController::class, 'destroy'])->whereNumber('token')->name('api-tokens.destroy');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
