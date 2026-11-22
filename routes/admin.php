<?php

use App\Http\Controllers\Admin\CloudflareHostnameController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\CreditController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FailedJobController;
use App\Http\Controllers\Admin\GenerationController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\TourReportController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 * Admin console for platform administrators (users.is_admin).
 * Grant access with: php artisan admin:grant you@example.com
 */
Route::middleware(['auth', 'verified', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::patch('users/{user}/suspension', [UserController::class, 'suspension'])->name('users.suspension');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('users/{user}/impersonate', [ImpersonationController::class, 'start'])->middleware('throttle:20,1')->name('users.impersonate');

    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show'])->name('workspaces.show');
    Route::post('workspaces/{workspace}/subscription', [SubscriptionController::class, 'store'])->name('workspaces.subscription.store');
    Route::delete('workspaces/{workspace}/subscription', [SubscriptionController::class, 'destroy'])->name('workspaces.subscription.destroy');
    Route::post('workspaces/{workspace}/credits', [CreditController::class, 'store'])->middleware('throttle:30,1')->name('workspaces.credits.store');

    Route::resource('plans', PlanController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('cloudflare-hostnames', [CloudflareHostnameController::class, 'index'])->name('cloudflare-hostnames.index');
    Route::delete('cloudflare-hostnames/{hostnameId}', [CloudflareHostnameController::class, 'destroy'])->name('cloudflare-hostnames.destroy');

    Route::get('content', [ContentController::class, 'index'])->name('content.index');
    Route::get('reports', [TourReportController::class, 'index'])->name('reports.index');
    Route::patch('reports/{report}', [TourReportController::class, 'update'])->name('reports.update');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');

    Route::get('jobs', [FailedJobController::class, 'index'])->name('jobs.index');
    Route::post('jobs/{uuid}/retry', [FailedJobController::class, 'retry'])->name('jobs.retry');
    Route::delete('jobs/{uuid}', [FailedJobController::class, 'destroy'])->name('jobs.destroy');
    Route::delete('jobs', [FailedJobController::class, 'flush'])->name('jobs.flush');

    Route::get('system', SystemController::class)->name('system');
});

// Returning from "sign in as" must work while signed in as the other user.
Route::middleware(['auth'])->post('impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');
