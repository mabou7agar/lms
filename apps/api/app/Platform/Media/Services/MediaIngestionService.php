<?php

namespace App\Platform\Media\Services;

use App\Platform\Media\Events\MediaFailed;
use App\Platform\Media\Events\MediaProcessingStarted;
use App\Platform\Media\Events\MediaReady;
use App\Platform\Media\Events\MediaUploaded;
use App\Platform\Media\Exceptions\MediaTransitionException;
use App\Platform\Media\Exceptions\MediaUploadTokenException;
use App\Platform\Media\Ingestion\IngestionProviderManager;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaWebhookEvent;
use App\Platform\Shared\Media\Data\ProviderAssetStatus;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Media\Enums\MediaStatus;
use Illuminate\Support\Facades\DB;

/**
 * P2/W04 - Advances an asset through its ingestion lifecycle from three drivers, all guarded by
 * MediaStatus so an asset can never move backwards or resurrect:
 *   - finalizeUpload: the instructor confirms the browser upload. The single-use token is spent
 *     here; provider verify (never client metadata) is the source of truth.
 *   - processWebhook: a verified provider event. Idempotent (recorded by provider event id before
 *     side effects) and ordered (an out-of-order/backward transition is a silent no-op).
 *   - retry: re-verify an existing remote asset without creating a new one.
 */
class MediaIngestionService
{
    public function __construct(private readonly IngestionProviderManager $providers) {}

    /** Confirm a browser upload. Spends the single-use token and reads authoritative provider state. */
    public function finalizeUpload(MediaAsset $asset, string $uploadToken): MediaAsset
    {
        if ($asset->upload_token === null || ! hash_equals($asset->upload_token, $uploadToken)) {
            throw new MediaUploadTokenException('The upload token is invalid.');
        }

        if ($asset->upload_token_consumed_at !== null) {
            throw new MediaUploadTokenException('The upload token has already been used.');
        }

        if ($asset->upload_token_expires_at === null || $asset->upload_token_expires_at->isPast()) {
            throw new MediaUploadTokenException('The upload token has expired.');
        }

        $providerStatus = $this->providers->for($asset->provider)->verifyUpload((string) $asset->provider_ref);

        return DB::transaction(function () use ($asset, $providerStatus): MediaAsset {
            /** @var MediaAsset $locked */
            $locked = MediaAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->upload_token_consumed_at !== null) {
                throw new MediaUploadTokenException('The upload token has already been used.');
            }

            $locked->forceFill(['upload_token_consumed_at' => now()])->save();

            // Mark "bytes received" then apply whatever authoritative state the provider reports.
            if ($locked->status->canTransitionTo(MediaStatus::Uploaded)) {
                $locked->forceFill(['status' => MediaStatus::Uploaded->value])->save();
                MediaUploaded::dispatch((string) $locked->public_id);
            }

            $this->applyProviderStatus($locked, $providerStatus);

            return $locked->refresh();
        });
    }

    /**
     * Process one verified provider webhook. Signature is checked by the adapter before this runs.
     *
     * @param  array<string, string>  $headers
     */
    public function processWebhook(MediaProvider $provider, string $payload, array $headers): void
    {
        $event = $this->providers->for($provider)->parseWebhook($payload, $headers); // throws on bad signature

        DB::transaction(function () use ($provider, $event): void {
            $record = MediaWebhookEvent::query()->firstOrCreate(
                ['provider_event_id' => $event->id],
                [
                    'provider' => $provider->value,
                    'type' => $event->type,
                    'received_at' => now(),
                ],
            );

            if ($record->processed_at !== null) {
                return; // duplicate delivery — safe no-op
            }

            $asset = MediaAsset::query()
                ->where('provider_ref', $event->providerRef)
                ->lockForUpdate()
                ->first();

            if ($asset !== null) {
                $record->forceFill(['media_asset_id' => $asset->id])->save();

                // Out-of-order / backward events are dropped (never move an asset back), but the
                // event is still marked processed so it is not retried forever.
                if ($asset->status->canTransitionTo($event->status->status)) {
                    $this->applyProviderStatus($asset, $event->status);
                }
            }

            $record->forceFill(['processed_at' => now()])->save();
        });
    }

    /**
     * Retry a failed asset by re-verifying its EXISTING remote asset — never by creating a new
     * upload (no duplicate remote asset). Repairs local state from authoritative provider status.
     */
    public function retry(MediaAsset $asset): MediaAsset
    {
        if (! $asset->status->isRetryable()) {
            throw new MediaTransitionException('Only a failed asset can be retried.');
        }

        $providerStatus = $this->providers->for($asset->provider)->verifyUpload((string) $asset->provider_ref);

        return DB::transaction(function () use ($asset, $providerStatus): MediaAsset {
            /** @var MediaAsset $locked */
            $locked = MediaAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            // Clear the previous failure and move toward processing/ready as the provider reports.
            $locked->forceFill(['failure_code' => null, 'failure_message' => null])->save();

            $target = $providerStatus->status;

            // A failed asset whose remote is still processing re-enters processing; if the remote
            // is already ready we jump straight to ready. Both are permitted by MediaStatus.
            if ($target === MediaStatus::Ready && $locked->status->canTransitionTo(MediaStatus::Processing)) {
                $locked->forceFill(['status' => MediaStatus::Processing->value])->save();
            }

            $this->applyProviderStatus($locked, $providerStatus);

            return $locked->refresh();
        });
    }

    /**
     * Apply authoritative provider metadata + status to an asset and dispatch the matching lifecycle
     * event. Assumes the caller already checked canTransitionTo (or is inside finalize/retry where
     * the forward move is intended). Never trusts anything but the provider status.
     */
    private function applyProviderStatus(MediaAsset $asset, ProviderAssetStatus $status): void
    {
        if (! $asset->status->canTransitionTo($status->status)) {
            return;
        }

        $attributes = ['status' => $status->status->value];

        if ($status->playbackId !== null) {
            $attributes['playback_id'] = $status->playbackId;
        }
        if ($status->storageKey !== null) {
            $attributes['storage_key'] = $status->storageKey;
        }
        if ($status->mimeType !== null) {
            $attributes['mime_type'] = $status->mimeType;
        }
        if ($status->sizeBytes !== null) {
            $attributes['size_bytes'] = $status->sizeBytes;
        }
        if ($status->durationSeconds !== null) {
            $attributes['duration_seconds'] = $status->durationSeconds;
        }
        if ($status->width !== null) {
            $attributes['width'] = $status->width;
        }
        if ($status->height !== null) {
            $attributes['height'] = $status->height;
        }

        if ($status->status === MediaStatus::Ready) {
            $attributes['processing_progress'] = 100;
        }
        if ($status->status === MediaStatus::Failed) {
            $attributes['failure_code'] = $status->failureCode;
            $attributes['failure_message'] = $status->failureMessage;
        }

        $asset->forceFill($attributes)->save();

        match ($status->status) {
            MediaStatus::Processing => MediaProcessingStarted::dispatch((string) $asset->public_id),
            MediaStatus::Ready => MediaReady::dispatch((string) $asset->public_id),
            MediaStatus::Failed => MediaFailed::dispatch((string) $asset->public_id, $status->failureCode),
            default => null,
        };
    }
}
