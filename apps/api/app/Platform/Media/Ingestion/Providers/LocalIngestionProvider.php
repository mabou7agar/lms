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
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Development-only ingestion onto the framework's local filesystem. It mirrors the S3 provider's shape
 * (issue an upload target keyed by purpose + a uuid, then verify by reading the landed object back), but
 * requires no cloud credentials and — critically — persists the REAL uploaded bytes and serves them over
 * a plain public URL, so an admin upload (e.g. an instructor avatar) actually displays in dev.
 *
 * Bytes never travel over HTTP here. Because the dev API runs as a single `php artisan serve` worker, a
 * loopback PUT to the app's own URL would deadlock; instead createDirectUpload returns a `local://` target
 * plus localDisk/localKey, and the server-side admin uploader (MediaAdminUploadService) writes straight to
 * the disk. Selected only when config('media.ingestion.default') === 'local' — never in production.
 */
class LocalIngestionProvider implements IngestionProvider
{
    /** @param array<string, mixed> $config config('media.local') */
    public function __construct(private readonly array $config) {}

    public function name(): MediaProvider
    {
        return MediaProvider::Local;
    }

    public function createDirectUpload(DirectUploadRequest $request): DirectUploadInstructions
    {
        $key = $this->objectKey($request);
        $disk = $this->diskName();

        return new DirectUploadInstructions(
            providerRef: $key,
            // Sentinel, not a network URL: the admin uploader writes to the disk directly (localDisk/localKey).
            uploadUrl: 'local://'.$disk.'/'.$key,
            method: 'PUT',
            headers: ['Content-Type' => $request->mimeType],
            fields: [],
            expiresAt: now()->addSeconds((int) config('media.upload.token_ttl_seconds', 3600)),
            localDisk: $disk,
            localKey: $key,
        );
    }

    public function verifyUpload(string $providerRef): ProviderAssetStatus
    {
        $disk = $this->disk();

        if (! $disk->exists($providerRef)) {
            return new ProviderAssetStatus(
                status: MediaStatus::Failed,
                failureCode: 'object_missing',
                failureMessage: 'Uploaded object was not found on the local disk.',
            );
        }

        return new ProviderAssetStatus(
            status: MediaStatus::Ready,
            providerAssetRef: $providerRef,
            storageKey: $providerRef,
            mimeType: $this->safeMime($disk, $providerRef),
            sizeBytes: (int) $disk->size($providerRef),
        );
    }

    public function deleteRemote(string $providerRef): void
    {
        $disk = $this->disk();

        if ($disk->exists($providerRef)) {
            $disk->delete($providerRef);
        }
    }

    public function parseWebhook(string $payload, array $headers): ProviderWebhookEvent
    {
        // The local provider is fully synchronous (verifyUpload is authoritative); it has no webhook.
        throw new MediaWebhookSignatureException('The local media provider does not accept webhooks.');
    }

    /** Public delivery URL for a stored object, used by the picker preview in dev. */
    public function publicUrl(string $storageKey): string
    {
        return $this->disk()->url($storageKey);
    }

    private function objectKey(DirectUploadRequest $request): string
    {
        $ext = pathinfo($request->filename, PATHINFO_EXTENSION);
        $suffix = $ext !== '' ? '.'.strtolower($ext) : '';

        return sprintf('media/%s/%s%s', $request->purpose->value, Str::uuid(), $suffix);
    }

    private function safeMime(FilesystemAdapter $disk, string $key): ?string
    {
        try {
            $mime = $disk->mimeType($key);

            return is_string($mime) && $mime !== '' ? $mime : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function diskName(): string
    {
        return (string) ($this->config['disk'] ?? 'media_local');
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($this->diskName());

        return $disk;
    }
}
