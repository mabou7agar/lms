<?php

namespace App\Platform\Shared\Learning\Contracts;

use App\Contexts\Learning\Models\LearnerBlockProgress;
use App\Contexts\Learning\Support\NullLessonRequiredBlocksPort;

/**
 * Cross-context port DECLARED by Learning to learn which content blocks of a lesson are
 * required-for-completion. Implemented by the context that authors blocks (Authoring); Learning
 * only consumes it. Block identifiers are the block PUBLIC ids the learner runtime records against
 * {@see LearnerBlockProgress} — never internal autoincrement ids.
 *
 * The default binding {@see NullLessonRequiredBlocksPort} returns an
 * empty list (no block gates completion), so a lesson stays completable on its other requirements
 * until an authoring provider declares required blocks.
 */
interface LessonRequiredBlocksPort
{
    /**
     * Public ids of the blocks that must be completed before the lesson may complete.
     *
     * @return list<string>
     */
    public function requiredBlockIds(int $lessonId): array;
}
