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
 * P2/W04 - Object-storage ingestion for stored files (document/file/image). Issues a presigned PUT
 * straight to the bucket via the framework 's3' disk (no bytes through the app), and verifies the
 * object landed by reading its size/mime back from the disk (authoritative — client metadata is
 * never trusted). S3 has no native asset webhook, so parseWebhook accepts a generic HMAC-signed
 * completion callback. Storage credentials live in the disk config, read only here.
 */
class S3IngestionProvider implements IngestionProvider
{
    /** @param array<string, mixed> $config config('media.s3') */
    public function __construct(private readonly array $config) {}

    public function name(): MediaProvider
    {
        return MediaProvider::S3;
    }

    public function createDirectUpload(DirectUploadRequest $request): DirectUploadInstructions
    {
        $key = $this->objectKey($request);
        $ttl = (int) ($this->config['presign_ttl_seconds'] ?? 3600);

        // temporaryUploadUrl returns ['url' => ..., 'headers' => [...]] for a presigned PUT.
        $signed = $this->disk()->temporaryUploadUrl($key, now()->addSeconds($ttl), [
            'ContentType' => $request->mimeType,
        ]);

        return new DirectUploadInstructions(
            providerRef: $key,
            uploadUrl: (string) $signed['url'],
            method: 'PUT',
            headers: array_map(static fn ($v): string => (string) $v, (array) ($signed['headers'] ?? [])),
            fields: [],
            expiresAt: now()->addSeconds($ttl),
        );
    }

    public function verifyUpload(string $providerRef): ProviderAssetStatus
    {
        $disk = $this->disk();

        if (! $disk->exists($providerRef)) {
            return new ProviderAssetStatus(
                status: MediaStatus::Failed,
                failureCode: 'object_missing',
                failureMessage: 'Uploaded object was not found in storage.',
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
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        $given = $headers['X-Signature'] ?? $headers['x-signature'] ?? '';

        if ($secret === '' || $given === '' || ! hash_equals(hash_hmac('sha256', $payload, $secret), $given)) {
            throw new MediaWebhookSignatureException('Invalid storage webhook signature.');
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($payload, true) ?: [];
        $ref = (string) ($data['provider_ref'] ?? $data['key'] ?? '');
        $status = MediaStatus::tryFrom((string) ($data['status'] ?? '')) ?? MediaStatus::Uploaded;

        return new ProviderWebhookEvent(
            id: (string) ($data['id'] ?? ''),
            type: (string) ($data['type'] ?? 's3.object.completed'),
            providerRef: $ref,
            status: new ProviderAssetStatus(
                status: $status,
                providerAssetRef: $ref,
                storageKey: $ref !== '' ? $ref : null,
                mimeType: isset($data['mime_type']) ? (string) $data['mime_type'] : null,
                sizeBytes: isset($data['size_bytes']) ? (int) $data['size_bytes'] : null,
            ),
        );
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

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) ($this->config['disk'] ?? 's3'));

        return $disk;
    }
}
