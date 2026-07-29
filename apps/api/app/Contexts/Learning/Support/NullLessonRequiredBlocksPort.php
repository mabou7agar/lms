<?php

namespace App\Contexts\Learning\Support;

use App\Platform\Shared\Learning\Contracts\LessonRequiredBlocksPort;

/**
 * Default for {@see LessonRequiredBlocksPort}: no lesson declares required blocks, so block
 * completion never gates lesson completion until an authoring provider is bound.
 */
final class NullLessonRequiredBlocksPort implements LessonRequiredBlocksPort
{
    /**
     * @return list<string>
     */
    public function requiredBlockIds(int $lessonId): array
    {
        return [];
    }
}
