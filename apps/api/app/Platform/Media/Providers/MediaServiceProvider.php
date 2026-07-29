<?php

namespace App\Platform\Media\Providers;

use App\Platform\Media\Ingestion\IngestionProviderManager;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Playback\PlaybackTokenManager;
use App\Platform\Media\Policies\MediaAssetPolicy;
use App\Platform\Media\Ports\MediaReferenceAdapter;
use App\Platform\Media\Ports\NullMediaEnrollmentPort;
use App\Platform\Shared\Media\Contracts\MediaEnrollmentPort;
use App\Platform\Shared\Media\Contracts\MediaReferencePort;
use App\Platform\Shared\Media\Contracts\PlaybackPort;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;

/**
 * Media platform wiring. Loads the Media migrations + route files (via BaseDomainServiceProvider),
 * registers MediaAssetPolicy, and binds:
 *  - PlaybackPort            -> config-resolved signer (unchanged; relocated from Learning).
 *  - IngestionProviderManager -> resolves the per-type ingestion adapter (mux | s3 | fake).
 *  - MediaReferencePort      -> the cross-context safe seam other contexts consume.
 *  - MediaEnrollmentPort     -> deny-by-default; Learning rebinds a real implementation later.
 *
 * Already registered in bootstrap/providers.php (loads early, before Identity/Catalog); the added
 * bindings are lazy so they resolve after those contexts have bound CourseAccessPort at runtime.
 */
class MediaServiceProvider extends BaseDomainServiceProvider
{
    /** @var list<string> */
    protected array $routeFiles = [
        'routes/media_admin.php',
        'routes/media_webhooks.php',
    ];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        MediaAsset::class => MediaAssetPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/media.php', 'media');

        // Playback signing (unchanged): fake | s3 | cloudfront | mux via learning.playback.provider.
        $this->app->bind(PlaybackPort::class, fn ($app) => $app->make(PlaybackTokenManager::class)->resolve());

        $this->app->singleton(IngestionProviderManager::class);

        $this->app->bind(MediaReferencePort::class, MediaReferenceAdapter::class);

        // Deny-by-default; Learning overrides with a real enrollment/publication-aware implementation.
        $this->app->bind(MediaEnrollmentPort::class, NullMediaEnrollmentPort::class);
    }
}
