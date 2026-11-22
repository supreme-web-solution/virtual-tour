<?php

use App\Http\Controllers\AI\AiStudioController;
use App\Http\Controllers\AI\GenerationController;
use App\Http\Controllers\AI\PhotoAnalysisController;
use App\Http\Controllers\AI\PhotoPanoramaController;
use App\Http\Controllers\AI\ScenePlanController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleConnectionController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MediaAssetController;
use App\Http\Controllers\PhotoEnhanceController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\PropertyImportController;
use App\Http\Controllers\PublicTourController;
use App\Http\Controllers\PublicTourEngagementController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TourCreateController;
use App\Http\Controllers\TourFloorplanController;
use App\Http\Controllers\TourHotspotController;
use App\Http\Controllers\TourLeadTestController;
use App\Http\Controllers\TourPublishController;
use App\Http\Controllers\TourSceneController;
use App\Http\Controllers\TourStreetViewController;
use App\Http\Controllers\Videos\PublicVideoController;
use App\Http\Controllers\Videos\VideoActionController;
use App\Http\Controllers\Videos\VideoProjectController;
use App\Http\Controllers\Videos\VideoShotController;
use App\Http\Controllers\VirtualTourController;
use App\Http\Controllers\Webhooks\SkyboxWebhookController;
use Illuminate\Support\Facades\Route;

// No landing page: visitors go to the login page, signed-in users to the dashboard.
Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'verified', 'workspace'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('properties', PropertyController::class);
    Route::post('properties/{property}/duplicate', [PropertyController::class, 'duplicate'])->name('properties.duplicate');

    Route::get('media', [MediaAssetController::class, 'index'])->name('media.index');
    Route::post('media/signature', [MediaAssetController::class, 'signature'])
        ->middleware('throttle:60,1')
        ->name('media.signature');
    Route::post('media', [MediaAssetController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('media.store');
    Route::patch('media/{media}', [MediaAssetController::class, 'update'])->name('media.update');
    Route::delete('media', [MediaAssetController::class, 'destroyMany'])->name('media.destroy-many');
    Route::delete('media/{media}', [MediaAssetController::class, 'destroy'])->name('media.destroy');
    Route::get('media/pick', [MediaAssetController::class, 'pick'])->name('media.pick');
    Route::get('media/enhance/options', [PhotoEnhanceController::class, 'options'])->name('media.enhance.options');
    Route::post('media/{media}/enhance', [PhotoEnhanceController::class, 'store'])->middleware('throttle:20,1')->name('media.enhance');
    Route::get('media/enhancements/{generation}', [PhotoEnhanceController::class, 'show'])->name('media.enhancements.show');

    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('analytics/export', [AnalyticsController::class, 'export'])->middleware('throttle:20,1')->name('analytics.export');
    Route::patch('leads/{lead}', [AnalyticsController::class, 'markLead'])->name('leads.mark');
    Route::delete('leads/{lead}', [AnalyticsController::class, 'destroyLead'])->name('leads.destroy');

    Route::get('billing', [BillingController::class, 'index'])->name('billing.index');

    Route::get('brand', [BrandController::class, 'index'])->name('brand.index');
    Route::patch('brand', [BrandController::class, 'update'])->name('brand.update');
    Route::patch('brand/channel', [BrandController::class, 'updateChannel'])->name('brand.channel.update');
    Route::post('brand/domain', [BrandController::class, 'storeDomain'])->middleware('throttle:10,1')->name('brand.domain.store');
    Route::post('brand/domain/verify', [BrandController::class, 'verifyDomain'])->middleware('throttle:20,1')->name('brand.domain.verify');
    Route::delete('brand/domain', [BrandController::class, 'destroyDomain'])->name('brand.domain.destroy');

    Route::get('imports', [PropertyImportController::class, 'index'])->name('imports.index');
    Route::post('imports', [PropertyImportController::class, 'store'])->middleware('throttle:10,1')->name('imports.store');
    Route::get('imports/{import}', [PropertyImportController::class, 'show'])->name('imports.show');
    Route::post('imports/{import}/complete', [PropertyImportController::class, 'complete'])->name('imports.complete');

    Route::get('team', [TeamController::class, 'index'])->name('team.index');
    Route::patch('team/workspace', [TeamController::class, 'rename'])->name('team.rename');
    Route::post('team/invitations', [TeamController::class, 'invite'])->middleware('throttle:20,1')->name('team.invite');
    Route::delete('team/invitations/{invitation}', [TeamController::class, 'revokeInvitation'])->name('team.invitations.revoke');
    Route::post('team/invitations/{invitation}/resend', [TeamController::class, 'resendInvitation'])->middleware('throttle:10,1')->name('team.invitations.resend');

    Route::post('comments', [CommentController::class, 'store'])->middleware('throttle:60,1')->name('comments.store');
    Route::patch('comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
    Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
    Route::patch('team/members/{member}', [TeamController::class, 'updateRole'])->name('team.members.update');
    Route::delete('team/members/{member}', [TeamController::class, 'remove'])->name('team.members.remove');
    Route::post('workspaces/{workspace}/switch', [TeamController::class, 'switch'])->name('workspaces.switch');

    Route::resource('videos', VideoProjectController::class)->except(['edit']);
    Route::scopeBindings()->group(function () {
        Route::put('videos/{video}/shots/order', [VideoShotController::class, 'reorder'])->name('videos.shots.reorder');
        Route::patch('videos/{video}/shots/{shot}', [VideoShotController::class, 'update'])->name('videos.shots.update');
        Route::delete('videos/{video}/shots/{shot}', [VideoShotController::class, 'destroy'])->name('videos.shots.destroy');
        Route::post('videos/{video}/shots/{shot}/regenerate', [VideoActionController::class, 'regenerateShot'])->middleware('throttle:30,1')->name('videos.shots.regenerate');
    });
    Route::post('videos/{video}/clips', [VideoActionController::class, 'generateClips'])->middleware('throttle:20,1')->name('videos.clips.generate');
    Route::post('videos/{video}/music', [VideoActionController::class, 'generateMusic'])->middleware('throttle:20,1')->name('videos.music.generate');
    Route::post('videos/{video}/render', [VideoActionController::class, 'render'])->middleware('throttle:20,1')->name('videos.render');
    Route::post('videos/{video}/publish', [VideoActionController::class, 'publish'])->name('videos.publish');
    Route::delete('videos/{video}/publish', [VideoActionController::class, 'unpublish'])->name('videos.unpublish');

    // One-click tour creation (the Create tour dialog).
    Route::get('tours/create/options', [TourCreateController::class, 'options'])->name('tours.create.options');
    Route::post('tours/create/describe', [TourCreateController::class, 'describe'])->middleware('throttle:10,1')->name('tours.create.describe');
    Route::post('tours/create/photos', [TourCreateController::class, 'photos'])->middleware('throttle:10,1')->name('tours.create.photos');
    Route::post('tours/create/panoramas', [TourCreateController::class, 'panoramas'])->middleware('throttle:20,1')->name('tours.create.panoramas');
    Route::post('tours/create/link/{import}', [TourCreateController::class, 'link'])->middleware('throttle:10,1')->name('tours.create.link');
    Route::get('tours/{tour}/progress', [TourCreateController::class, 'progress'])->name('tours.progress');

    Route::get('tours', [VirtualTourController::class, 'index'])->name('tours.index');
    Route::post('tours', [VirtualTourController::class, 'store'])->name('tours.store');
    Route::get('tours/{tour}/edit', [VirtualTourController::class, 'edit'])->name('tours.edit');
    Route::patch('tours/{tour}', [VirtualTourController::class, 'update'])->name('tours.update');
    Route::delete('tours/{tour}', [VirtualTourController::class, 'destroy'])->name('tours.destroy');
    Route::get('tours/{tour}/preview', [PublicTourController::class, 'preview'])->name('tours.preview');
    Route::post('tours/{tour}/lead-test', TourLeadTestController::class)->middleware('throttle:5,1')->name('tours.lead-test');
    Route::get('tours/{tour}/street-view', [TourStreetViewController::class, 'show'])->name('tours.street-view.show');
    Route::post('tours/{tour}/street-view', [TourStreetViewController::class, 'store'])->middleware('throttle:10,1')->name('tours.street-view.store');
    Route::delete('tours/{tour}/street-view/{scene}', [TourStreetViewController::class, 'destroy'])->name('tours.street-view.destroy');
    Route::get('integrations/google/connect', [GoogleConnectionController::class, 'connect'])->name('google.connect');
    Route::get('integrations/google/callback', [GoogleConnectionController::class, 'callback'])->name('google.callback');
    Route::delete('integrations/google', [GoogleConnectionController::class, 'disconnect'])->name('google.disconnect');
    Route::post('tours/{tour}/publish', [TourPublishController::class, 'store'])->name('tours.publish');
    Route::delete('tours/{tour}/publish', [TourPublishController::class, 'destroy'])->name('tours.unpublish');

    Route::scopeBindings()->group(function () {
        Route::post('tours/{tour}/scenes', [TourSceneController::class, 'store'])->name('tours.scenes.store');
        Route::put('tours/{tour}/scenes/order', [TourSceneController::class, 'reorder'])->name('tours.scenes.reorder');
        Route::patch('tours/{tour}/scenes/{scene}', [TourSceneController::class, 'update'])->name('tours.scenes.update');
        Route::post('tours/{tour}/scenes/{scene}/duplicate', [TourSceneController::class, 'duplicate'])->name('tours.scenes.duplicate');
        Route::delete('tours/{tour}/scenes/{scene}', [TourSceneController::class, 'destroy'])->name('tours.scenes.destroy');

        Route::post('tours/{tour}/scenes/{scene}/hotspots', [TourHotspotController::class, 'store'])->name('tours.hotspots.store');
        Route::patch('tours/{tour}/hotspots/{hotspot}', [TourHotspotController::class, 'update'])->name('tours.hotspots.update');
        Route::delete('tours/{tour}/hotspots/{hotspot}', [TourHotspotController::class, 'destroy'])->name('tours.hotspots.destroy');

        Route::post('tours/{tour}/floorplans', [TourFloorplanController::class, 'store'])->name('tours.floorplans.store');
        Route::patch('tours/{tour}/floorplans/{floorplan}', [TourFloorplanController::class, 'update'])->name('tours.floorplans.update');
        Route::delete('tours/{tour}/floorplans/{floorplan}', [TourFloorplanController::class, 'destroy'])->name('tours.floorplans.destroy');
        Route::put('tours/{tour}/floorplans/{floorplan}/markers', [TourFloorplanController::class, 'placeMarker'])->name('tours.floorplans.markers.place');
        Route::delete('tours/{tour}/floorplans/{floorplan}/markers', [TourFloorplanController::class, 'removeMarker'])->name('tours.floorplans.markers.remove');
    });
});

Route::middleware(['auth', 'verified', 'workspace'])->prefix('ai-studio')->name('ai-studio.')->group(function () {
    Route::get('/', [AiStudioController::class, 'index'])->name('index');
    Route::get('styles', [AiStudioController::class, 'styles'])->name('styles');

    Route::post('plans', [ScenePlanController::class, 'store'])->middleware('throttle:20,1')->name('plans.store');
    Route::get('plans/{plan}', [ScenePlanController::class, 'show'])->name('plans.show');
    Route::post('plans/{plan}/generate', [ScenePlanController::class, 'generate'])->middleware('throttle:20,1')->name('plans.generate');

    Route::post('analyses', [PhotoAnalysisController::class, 'store'])->middleware('throttle:20,1')->name('analyses.store');
    Route::get('analyses/{analysis}', [PhotoAnalysisController::class, 'show'])->name('analyses.show');
    Route::post('analyses/{analysis}/convert', [PhotoAnalysisController::class, 'convert'])->middleware('throttle:20,1')->name('analyses.convert');

    Route::post('photo-panoramas', [PhotoPanoramaController::class, 'store'])->middleware('throttle:20,1')->name('photo-panoramas.store');

    Route::post('generations/{generation}/regenerate', [GenerationController::class, 'regenerate'])->middleware('throttle:20,1')->name('generations.regenerate');
    Route::post('generations/{generation}/cancel', [GenerationController::class, 'cancel'])->name('generations.cancel');
});

// Invitation links work for guests (they are asked to sign in or register first).
Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])
    ->middleware(['auth', 'throttle:20,1'])
    ->name('invitations.accept');

Route::post('webhooks/skybox', SkyboxWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.skybox');

Route::prefix('t/{publicId}')->where(['publicId' => '[a-z0-9]{12}'])->name('tours.public.')->group(function () {
    Route::post('visits', [PublicTourEngagementController::class, 'startVisit'])->middleware('throttle:60,1')->name('visits');
    Route::post('events', [PublicTourEngagementController::class, 'events'])->middleware('throttle:240,1')->name('events');
    Route::post('leads', [PublicTourEngagementController::class, 'lead'])->middleware(['throttle:20,1', 'throttle:200,60'])->name('leads');
    Route::post('reports', [PublicTourEngagementController::class, 'report'])->middleware('throttle:5,1')->name('reports');
});

Route::prefix('v/{publicId}')->where(['publicId' => '[a-z0-9]{12}'])->name('videos.public.')->group(function () {
    Route::post('events', [PublicVideoController::class, 'event'])->middleware('throttle:120,1')->name('events');
    Route::get('download', [PublicVideoController::class, 'download'])->middleware('throttle:30,1')->name('download');
});

Route::get('v/{publicId}/{slug?}', [PublicVideoController::class, 'show'])
    ->where('publicId', '[a-z0-9]{12}')
    ->name('videos.public');

Route::get('c/{slug}', ChannelController::class)
    ->where('slug', '[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]')
    ->name('channel.show');

Route::get('t/{publicId}/{slug?}', [PublicTourController::class, 'show'])
    ->where('publicId', '[a-z0-9]{12}')
    ->name('tours.public');

// Asked by the web server before issuing a certificate for a custom domain.
Route::get('domains/tls-check', [BrandController::class, 'tlsCheck'])->middleware('throttle:120,1')->name('domains.tls-check');

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
