<?php

namespace App\Providers;

use App\Contracts\AI\StructuredOutputProvider;
use App\Contracts\Image\ImageEnhancementProvider;
use App\Contracts\Media\MediaStorage;
use App\Contracts\Music\MusicGenerationProvider;
use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Contracts\Video\VideoGenerationProvider;
use App\Contracts\Video\VideoRenderer;
use App\Models\User;
use App\Services\AI\OpenAIProvider;
use App\Services\Billing\EntitlementService;
use App\Services\Domains\BrandPresenter;
use App\Services\Domains\PublicUrl;
use App\Services\Image\OpenAIImageProvider;
use App\Services\Media\CloudinaryMediaStorage;
use App\Services\Music\FalMusicProvider;
use App\Services\Panorama\SkyboxPanoramaProvider;
use App\Services\Video\FalVideoProvider;
use App\Services\Video\Render\FfmpegVideoRenderer;
use App\Support\ProviderConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProviderConfiguration::class);
        $this->app->scoped(EntitlementService::class);
        $this->app->scoped(PublicUrl::class);
        $this->app->scoped(BrandPresenter::class);
        $this->app->bind(MediaStorage::class, CloudinaryMediaStorage::class);
        $this->app->bind(StructuredOutputProvider::class, OpenAIProvider::class);
        $this->app->bind(PanoramaGenerationProvider::class, SkyboxPanoramaProvider::class);
        $this->app->bind(VideoGenerationProvider::class, FalVideoProvider::class);
        $this->app->bind(MusicGenerationProvider::class, FalMusicProvider::class);
        $this->app->bind(VideoRenderer::class, FfmpegVideoRenderer::class);
        $this->app->bind(ImageEnhancementProvider::class, OpenAIImageProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Platform administrators (admin console). Not a workspace role.
        Gate::define('admin', fn (User $user): bool => (bool) $user->is_admin);

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)->by($request->user()?->currentAccessToken()->id ?? $request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
