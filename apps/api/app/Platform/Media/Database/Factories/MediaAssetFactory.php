<?php

namespace App\Platform\Media\Database\Factories;

use App\Platform\Media\Enums\MediaVisibility;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaAsset>
 *
 * Produces a self-consistent asset. Defaults to a fake-provider, freshly-created video awaiting
 * upload; states() move it along the lifecycle for service/policy tests.
 */
class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    public function definition(): array
    {
        return [
            'type' => MediaType::Video->value,
            'status' => MediaStatus::Created->value,
            'provider' => MediaProvider::Fake->value,
            'purpose' => MediaPurpose::LessonVideo->value,
            // Secure by default: assets are PRIVATE until an authorized actor raises visibility.
            'visibility' => MediaVisibility::Private->value,
            'created_by' => 1,
            'course_id' => null,
            'original_filename' => 'lecture.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 10 * 1024 * 1024,
            'duration_seconds' => null,
            'width' => null,
            'height' => null,
            'provider_ref' => null,
            'playback_id' => null,
            'storage_key' => null,
            'thumbnail_ref' => null,
            'processing_progress' => 0,
            'failure_code' => null,
            'failure_message' => null,
            'metadata' => null,
            'idempotency_key' => (string) Str::uuid(),
            'upload_token' => null,
            'upload_token_expires_at' => null,
            'upload_token_consumed_at' => null,
        ];
    }

    public function ownedBy(int $actorId): self
    {
        return $this->state(fn () => ['created_by' => $actorId]);
    }

    public function forCourse(int $courseId): self
    {
        return $this->state(fn () => ['course_id' => $courseId]);
    }

    /** Raise visibility to PUBLIC (P1) — for testing public URL resolution. */
    public function publicVisibility(): self
    {
        return $this->state(fn () => ['visibility' => MediaVisibility::Public->value]);
    }

    /** Raise visibility to AUTHENTICATED (P1) — resolves to a signed URL in a public renderer. */
    public function authenticatedVisibility(): self
    {
        return $this->state(fn () => ['visibility' => MediaVisibility::Authenticated->value]);
    }

    /** Place the asset inside an organizational folder (Phase 8 / D1). */
    public function inFolder(int $folderId): self
    {
        return $this->state(fn () => ['folder_id' => $folderId]);
    }

    /** A live, single-use upload token bound to a provider ref, awaiting finalize. */
    public function awaitingUpload(): self
    {
        return $this->state(fn () => [
            'status' => MediaStatus::WaitingForUpload->value,
            'provider_ref' => 'fake-'.Str::random(20),
            'upload_token' => Str::random(64),
            'upload_token_expires_at' => now()->addHour(),
            'upload_token_consumed_at' => null,
        ]);
    }

    /** Token already spent (upload finalized) — a second finalize must be rejected. */
    public function tokenConsumed(): self
    {
        return $this->state(fn () => ['upload_token_consumed_at' => now()]);
    }

    /** Token still present but past its expiry. */
    public function tokenExpired(): self
    {
        return $this->state(fn () => [
            'status' => MediaStatus::WaitingForUpload->value,
            'provider_ref' => 'fake-'.Str::random(20),
            'upload_token' => Str::random(64),
            'upload_token_expires_at' => now()->subMinute(),
        ]);
    }

    public function processing(): self
    {
        return $this->state(fn () => [
            'status' => MediaStatus::Processing->value,
            'provider_ref' => 'fake-'.Str::random(20),
        ]);
    }

    public function ready(): self
    {
        return $this->state(fn () => [
            'status' => MediaStatus::Ready->value,
            'provider_ref' => 'fake-'.Str::random(20),
            'playback_id' => 'fake-play-'.Str::random(16),
            'duration_seconds' => 120,
            'width' => 1280,
            'height' => 720,
            'processing_progress' => 100,
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn () => [
            'status' => MediaStatus::Failed->value,
            'provider_ref' => 'fake-'.Str::random(20),
            'failure_code' => 'processing_error',
            'failure_message' => 'Transcode failed.',
        ]);
    }
}
