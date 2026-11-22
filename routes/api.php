<?php

use App\Http\Controllers\Api\LeadApiController;
use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\MediaApiController;
use App\Http\Controllers\Api\PropertyApiController;
use App\Http\Controllers\Api\TourApiController;
use App\Http\Controllers\Api\WorkspaceApiController;
use Illuminate\Support\Facades\Route;

/*
| Read-only API for a workspace's own integrations. A token is created in
| Settings > API access and is tied to one workspace.
*/
Route::middleware(['auth:sanctum', 'api.workspace', 'throttle:api'])->group(function () {
    Route::prefix('v1')->name('api.')->group(function () {
        Route::get('workspace', [WorkspaceApiController::class, 'show'])->name('workspace');
        Route::get('properties', [PropertyApiController::class, 'index'])->name('properties.index');
        Route::get('properties/{property}', [PropertyApiController::class, 'show'])->name('properties.show');
        Route::get('tours', [TourApiController::class, 'index'])->name('tours.index');
        Route::get('tours/{tour}', [TourApiController::class, 'show'])->name('tours.show');
        Route::get('leads', [LeadApiController::class, 'index'])->name('leads.index');
        Route::get('media', [MediaApiController::class, 'index'])->name('media.index');
    });

    // Model Context Protocol endpoint for AI assistants.
    Route::post('mcp', McpController::class)->name('api.mcp');
});
