<?php

namespace App\Platform\Shared\Media\Contracts;

use App\Platform\Shared\Media\Data\DirectUploadInstructions;
use App\Platform\Shared\Media\Data\DirectUploadRequest;
use App\Platform\Shared\Media\Data\ProviderAssetStatus;
use App\Platform\Shared\Media\Data\ProviderWebhookEvent;
use App\Platform\Shared\Media\Enums\MediaProvider;

/**
 * P2/W04 - Storage-neutral ingestion adapter. One implementation per backend (Mux for streamed
 * video/audio, S3 for stored files, a Fake for tests). All provider secrets live inside the
 * adapter; callers pass only DTOs and receive only DTOs — never a raw provider SDK object.
 *
 * Every method must be safe to call more than once for the same logical operation: creating an
 * upload is bound to a caller-supplied idempotency reference, and verify/delete are naturally
 * idempotent against the provider reference.
 */
interface IngestionProvider
{
    public function name(): MediaProvider;

    /** Issue signed, expiring direct-upload instructions. Never proxies bytes through the app. */
    public function createDirectUpload(DirectUploadRequest $request): DirectUploadInstructions;

    /**
     * Confirm an upload with the provider and read authoritative metadata. Frontend-reported
     * metadata is never trusted; this is the source of truth for size/mime/duration/dimensions.
     */
    public function verifyUpload(string $providerRef): ProviderAssetStatus;

    /** Remove the remote asset. Must not throw if the asset is already gone. */
    public function deleteRemote(string $providerRef): void;

    /**
     * Verify a webhook signature and normalise the payload. Throws when the signature is invalid.
     *
     * @param  array<string, string>  $headers
     */
    public function parseWebhook(string $payload, array $headers): ProviderWebhookEvent;
}
