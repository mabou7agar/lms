<?php

namespace App\Platform\Media\Listeners;

use App\Platform\Media\Events\MediaReady;
use App\Platform\Media\Jobs\GenerateImageVariantsJob;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Enums\MediaType;

/**
 * Phase A / D6 - The additive ingestion hook. Listens to the EXISTING MediaReady lifecycle event and,
 * for image assets only, queues GenerateImageVariantsJob. It changes nothing in the ingestion flow:
 * MediaIngestionService still dispatches MediaReady exactly as before; this listener merely observes it.
 * Registered via Event::listen in MediaServiceProvider (Media-owned), so no shared provider is touched.
 *
 * The listener is intentionally trivial and synchronous (a single cheap dispatch): all heavy work is in
 * the job, which is afterCommit so it waits for the finalize transaction that raised the event to commit.
 */
class GenerateImageVariantsOnReady
{
    public function handle(MediaReady $event): void
    {
        $asset = MediaAsset::query()->where('public_id', $event->mediaId)->first();

        if ($asset === null || $asset->type !== MediaType::Image) {
            return; // only images have a variant pipeline; video/audio/documents are untouched
        }

        GenerateImageVariantsJob::dispatch($asset->getKey())
            ->onQueue((string) config('media.images.queue', 'default'));
    }
}
