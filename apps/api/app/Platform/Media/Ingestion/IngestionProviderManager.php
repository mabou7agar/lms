<?php

namespace App\Platform\Media\Ingestion;

use App\Platform\Media\Ingestion\Providers\FakeIngestionProvider;
use App\Platform\Media\Ingestion\Providers\LocalIngestionProvider;
use App\Platform\Media\Ingestion\Providers\MuxIngestionProvider;
use App\Platform\Media\Ingestion\Providers\S3IngestionProvider;
use App\Platform\Shared\Media\Contracts\IngestionProvider;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * P2/W04 - Resolves the concrete IngestionProvider for a media type or an explicit provider enum,
 * mirroring PlaybackTokenManager. Vendor config is injected into each adapter here, so no other
 * code reads ingestion secrets. Provider selection: config('media.ingestion.default') === 'fake'
 * forces the fake adapter everywhere (dev/test); otherwise streamed types go to Mux and stored
 * types to S3.
 */
class IngestionProviderManager
{
    public function __construct(private readonly Container $app) {}

    /** The MediaProvider that should ingest a given media type under the current config. */
    public function providerFor(MediaType $type): MediaProvider
    {
        $default = MediaProvider::tryFrom((string) config('media.ingestion.default', 'fake'))
            ?? MediaProvider::Fake;

        if ($default === MediaProvider::Fake) {
            return MediaProvider::Fake;
        }

        // Dev local disk: persist EVERY type's bytes on the local disk. Streamed types (video/audio) are
        // stored locally too rather than falling back to the credential-free fake adapter — the fake path
        // POSTs to an unreachable host (upload.fake.test), which only 500s an admin upload in local dev.
        // Nothing goes to Fake while ingestion is 'local'. Production is unaffected (default is s3/mux there).
        if ($default === MediaProvider::Local) {
            return MediaProvider::Local;
        }

        return $type->isStreamed() ? MediaProvider::Mux : MediaProvider::S3;
    }

    /** Resolve the adapter for a media type (used at direct-upload creation). */
    public function forType(MediaType $type): IngestionProvider
    {
        return $this->for($this->providerFor($type));
    }

    /** Resolve the adapter for an explicit provider (used at finalize / webhook / delete). */
    public function for(MediaProvider $provider): IngestionProvider
    {
        return match ($provider) {
            MediaProvider::Mux => new MuxIngestionProvider((array) config('media.mux')),
            MediaProvider::S3 => new S3IngestionProvider((array) config('media.s3')),
            MediaProvider::Local => new LocalIngestionProvider((array) config('media.local')),
            MediaProvider::Fake => $this->app->make(FakeIngestionProvider::class),
            MediaProvider::External => throw new InvalidArgumentException('External media is not ingested.'),
        };
    }
}
