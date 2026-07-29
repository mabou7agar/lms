<?php

namespace App\Platform\Media\Ingestion\Providers;

use App\Platform\Media\Exceptions\MediaWebhookSignatureException;
use App\Platform\Shared\Media\Contracts\IngestionProvider;
use App\Platform\Shared\Media\Data\DirectUploadInstructions;
use App\Platform\Shared\Media\Data\DirectUploadRequest;
use App\Platform\Shared\Media\Data\ProviderAssetStatus;
use App\Platform\Shared\Media\Data\ProviderWebhookEvent;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Media\Enums\MediaStatus;

/**
 * P2/W04 - Deterministic, credential-free ingestion adapter for tests + local dev. Contacts no
 * vendor: it derives a stable provider ref from the caller's idempotency key, "verifies" any ref as
 * ready, and signs/normalises webhooks with a shared HMAC secret so a webhook fixture is easy to
 * build in a test. Secrets (the shared HMAC) are read only here.
 */
class FakeIngestionProvider implements IngestionProvider
{
    public function name(): MediaProvider
    {
        return MediaProvider::Fake;
    }

    public function createDirectUpload(DirectUploadRequest $request): DirectUploadInstructions
    {
        // Deterministic per idempotency key so a retried creation returns the same ref.
        $ref = 'fake-'.hash('sha256', $request->idempotencyKey);

        return new DirectUploadInstructions(
            providerRef: $ref,
            uploadUrl: 'https://upload.fake.test/'.$ref,
            method: 'PUT',
            headers: ['Content-Type' => $request->mimeType],
            fields: [],
            expiresAt: now()->addSeconds((int) config('media.upload.token_ttl_seconds', 3600)),
        );
    }

    public function verifyUpload(string $providerRef): ProviderAssetStatus
    {
        // The fake provider is instantaneous: a verified upload is immediately ready with stable,
        // derived metadata. This is the authoritative source (client metadata is never trusted).
        return new ProviderAssetStatus(
            status: MediaStatus::Ready,
            providerAssetRef: $providerRef,
            playbackId: 'fake-play-'.substr(hash('sha256', $providerRef), 0, 24),
            storageKey: 'fake/'.$providerRef,
            mimeType: 'video/mp4',
            sizeBytes: 10 * 1024 * 1024,
            durationSeconds: 120,
            width: 1280,
            height: 720,
        );
    }

    public function deleteRemote(string $providerRef): void
    {
        // Nothing to delete; deletion is idempotent by definition here.
    }

    public function parseWebhook(string $payload, array $headers): ProviderWebhookEvent
    {
        $expected = hash_hmac('sha256', $payload, (string) config('media.fake.webhook_secret', 'fake-media-secret'));
        $given = $headers['X-Fake-Signature'] ?? $headers['x-fake-signature'] ?? '';

        if ($given === '' || ! hash_equals($expected, $given)) {
            throw new MediaWebhookSignatureException('Invalid fake webhook signature.');
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($payload, true) ?: [];

        $status = MediaStatus::tryFrom((string) ($data['status'] ?? '')) ?? MediaStatus::Processing;

        return new ProviderWebhookEvent(
            id: (string) ($data['id'] ?? ''),
            type: (string) ($data['type'] ?? 'fake.event'),
            providerRef: (string) ($data['provider_ref'] ?? ''),
            status: new ProviderAssetStatus(
                status: $status,
                providerAssetRef: (string) ($data['provider_ref'] ?? ''),
                playbackId: isset($data['playback_id']) ? (string) $data['playback_id'] : null,
                storageKey: isset($data['storage_key']) ? (string) $data['storage_key'] : null,
                mimeType: isset($data['mime_type']) ? (string) $data['mime_type'] : null,
                sizeBytes: isset($data['size_bytes']) ? (int) $data['size_bytes'] : null,
                durationSeconds: isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null,
                width: isset($data['width']) ? (int) $data['width'] : null,
                height: isset($data['height']) ? (int) $data['height'] : null,
                failureCode: isset($data['failure_code']) ? (string) $data['failure_code'] : null,
                failureMessage: isset($data['failure_message']) ? (string) $data['failure_message'] : null,
            ),
        );
    }
}
