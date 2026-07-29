<?php

namespace App\Platform\Media\Services;

use App\Platform\Media\Events\MediaAttached;
use App\Platform\Media\Events\MediaDetached;
use App\Platform\Media\Exceptions\MediaAccessDeniedException;
use App\Platform\Media\Exceptions\MediaNotReadyException;
use App\Platform\Media\Exceptions\MediaValidationException;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaAttachment;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * P2/W04 - Records/removes where an asset is used. Enforces ownership + course scoping and readiness
 * as a second line of defence behind the policy: an asset bound to course A can never be attached to
 * content of course B, and only a ready asset may be attached. Attach is idempotent (unique usage
 * index). The attachment's existence is what blocks deletion, so usage counting lives here too.
 */
class MediaAttachmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function attach(
        MediaAsset $asset,
        int $actorId,
        string $attachableType,
        int $attachableId,
        string $role = 'attachment',
        ?int $courseId = null,
    ): MediaAttachment {
        if ($asset->created_by !== $actorId) {
            // No existence leak: a non-owner is told only that it is not found.
            throw new MediaAccessDeniedException;
        }

        if (! $asset->status->isPlayable()) {
            throw new MediaNotReadyException('Only a ready asset can be attached.');
        }

        // Cross-course guard: a course-bound asset may only attach within its own course.
        if ($asset->course_id !== null && $courseId !== null && $asset->course_id !== $courseId) {
            throw new MediaValidationException(
                'This media belongs to a different course and cannot be attached here.',
                ['field' => 'course_id'],
            );
        }

        return DB::transaction(function () use ($asset, $actorId, $attachableType, $attachableId, $role, $courseId): MediaAttachment {
            $attachment = MediaAttachment::query()->firstOrNew([
                'attachable_type' => $attachableType,
                'attachable_id' => $attachableId,
                'media_asset_id' => $asset->id,
            ]);

            if (! $attachment->exists) {
                $attachment->forceFill([
                    'media_asset_id' => $asset->id,
                    'attachable_type' => $attachableType,
                    'attachable_id' => $attachableId,
                    'role' => $role,
                    'course_id' => $courseId ?? $asset->course_id,
                    'attached_by' => $actorId,
                ])->save();

                MediaAttached::dispatch((string) $asset->public_id, $attachableType, $attachableId, $actorId);
                $this->audit->log('media.attached', $asset, [
                    'attachable_type' => $attachableType,
                    'attachable_id' => $attachableId,
                    'role' => $role,
                ], $actorId);
            }

            return $attachment;
        });
    }

    public function detach(MediaAsset $asset, int $actorId, string $attachableType, int $attachableId): void
    {
        if ($asset->created_by !== $actorId) {
            throw new MediaAccessDeniedException;
        }

        DB::transaction(function () use ($asset, $actorId, $attachableType, $attachableId): void {
            $deleted = MediaAttachment::query()
                ->where('media_asset_id', $asset->id)
                ->where('attachable_type', $attachableType)
                ->where('attachable_id', $attachableId)
                ->delete();

            if ($deleted > 0) {
                MediaDetached::dispatch((string) $asset->public_id, $attachableType, $attachableId, $actorId);
                $this->audit->log('media.detached', $asset, [
                    'attachable_type' => $attachableType,
                    'attachable_id' => $attachableId,
                ], $actorId);
            }
        });
    }

    public function usageCount(MediaAsset $asset): int
    {
        return MediaAttachment::query()->where('media_asset_id', $asset->id)->count();
    }
}
