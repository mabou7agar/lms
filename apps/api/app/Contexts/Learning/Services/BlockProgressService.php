<?php

namespace App\Contexts\Learning\Services;

use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LearnerBlockProgress;
use App\Platform\Shared\Services\BaseService;

/**
 * Records completion of a single content block. Idempotent per (enrollment, block_ref): recording
 * the same block twice is a no-op that returns the existing row, so repeated or duplicated client
 * events never create duplicates or move the completion timestamp.
 */
class BlockProgressService extends BaseService
{
    public function completeBlock(Enrollment $enrollment, int $userId, int $lessonId, string $blockRef): LearnerBlockProgress
    {
        $progress = LearnerBlockProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('block_ref', $blockRef)
            ->first();

        if ($progress !== null) {
            return $progress;
        }

        $progress = new LearnerBlockProgress;
        $progress->forceFill([
            'enrollment_id' => $enrollment->id,
            'user_id' => $userId,
            'lesson_id' => $lessonId,
            'block_ref' => $blockRef,
            'completed_at' => now(),
        ])->save();

        return $progress;
    }
}
