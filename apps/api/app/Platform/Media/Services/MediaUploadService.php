<?php

namespace App\Platform\Media\Services;

use App\Platform\Media\Events\MediaUploadCreated;
use App\Platform\Media\Exceptions\MediaValidationException;
use App\Platform\Media\Ingestion\Data\DirectUploadTicket;
use App\Platform\Media\Ingestion\IngestionProviderManager;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Data\DirectUploadRequest;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P2/W04 - Owns the "issue a direct-upload slot" use case. Bounds type/size against the purpose
 * BEFORE contacting a provider, binds the asset to the actor (+ optional course), and mints a
 * single-use, expiring finalize token. Idempotent by (created_by, idempotency_key): a retried
 * request reuses the same asset and re-issues instructions rather than creating a second remote
 * upload.
 */
class MediaUploadService
{
    public function __construct(private readonly IngestionProviderManager $providers) {}

    public function createDirectUpload(
        int $actorId,
        MediaType $type,
        MediaPurpose $purpose,
        string $filename,
        string $mimeType,
        int $sizeBytes,
        ?int $courseId,
        string $idempotencyKey,
    ): DirectUploadTicket {
        $this->assertAcceptable($type, $purpose, $sizeBytes);

        $provider = $this->providers->providerFor($type);

        return DB::transaction(function () use (
            $actorId, $type, $purpose, $filename, $mimeType, $sizeBytes, $courseId, $idempotencyKey, $provider
        ): DirectUploadTicket {
            $asset = MediaAsset::query()
                ->where('created_by', $actorId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($asset === null) {
                $asset = new MediaAsset;
                $asset->forceFill([
                    'type' => $type->value,
                    'status' => MediaStatus::Created->value,
                    'provider' => $provider->value,
                    'purpose' => $purpose->value,
                    'created_by' => $actorId,
                    'course_id' => $courseId,
                    'original_filename' => $filename,
                    'mime_type' => $mimeType,
                    'size_bytes' => $sizeBytes,
                    'idempotency_key' => $idempotencyKey,
                ])->save();
            }

            $request = new DirectUploadRequest(
                mediaPublicId: (string) $asset->public_id,
                type: $type,
                purpose: $purpose,
                filename: $filename,
                mimeType: $mimeType,
                sizeBytes: $sizeBytes,
                actorId: $actorId,
                courseId: $courseId,
                idempotencyKey: $idempotencyKey,
            );

            $instructions = $this->providers->for($provider)->createDirectUpload($request);

            $token = Str::random(64);

            $asset->forceFill([
                'status' => MediaStatus::WaitingForUpload->value,
                'provider_ref' => $instructions->providerRef,
                'upload_token' => $token,
                'upload_token_expires_at' => $instructions->expiresAt,
                'upload_token_consumed_at' => null,
            ])->save();

            MediaUploadCreated::dispatch((string) $asset->public_id, $actorId, $type->value, $provider->value);

            return new DirectUploadTicket($asset->refresh(), $instructions, $token);
        });
    }

    private function assertAcceptable(MediaType $type, MediaPurpose $purpose, int $sizeBytes): void
    {
        if (! $purpose->accepts($type)) {
            throw new MediaValidationException(
                "The {$type->value} type is not accepted for the {$purpose->value} purpose.",
                ['field' => 'type'],
            );
        }

        if ($sizeBytes <= 0) {
            throw new MediaValidationException('A positive file size is required.', ['field' => 'size_bytes']);
        }

        $max = $purpose->maxBytes();

        if ($max > 0 && $sizeBytes > $max) {
            throw new MediaValidationException(
                "File exceeds the maximum size for {$purpose->value} ({$max} bytes).",
                ['field' => 'size_bytes', 'max_bytes' => $max],
            );
        }
    }
}
