<?php

namespace App\Contexts\Learning\Services;

use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LearnerBlockProgress;
use App\Platform\Shared\Learning\Contracts\AssignmentRequirementPort;
use App\Platform\Shared\Learning\Contracts\LessonRequiredBlocksPort;
use App\Platform\Shared\Services\BaseService;

/**
 * The server-side rule that decides whether a lesson MAY complete. Two content requirements are
 * enforced here:
 *
 *   - Required blocks   — every block the lesson declares required (LessonRequiredBlocksPort) must
 *                         have a LearnerBlockProgress row for this enrollment.
 *   - Required assignment — if the lesson gates completion on an assignment
 *                         (AssignmentRequirementPort::hasRequired), it must be satisfied for the
 *                         learner. Bound to NullAssignmentRequirementPort until the Assignment
 *                         context ships, so this gate is inert-safe by default.
 *
 * The video requirement is layered on top by the completion Action (it needs lesson type + media
 * presence, which live outside this policy). Assessment requirement compatibility is handled at the
 * player/attempt layer via LessonAssessmentPort — see NOTES on assessment in the wiring manifest;
 * this policy does not itself gate on a quiz pass because no per-learner assessment-result port
 * exists to consult, and inventing one here would duplicate Assessment-owned state.
 */
class LessonCompletionPolicy extends BaseService
{
    public function __construct(
        private readonly AssignmentRequirementPort $assignments,
        private readonly LessonRequiredBlocksPort $requiredBlocks,
    ) {}

    public function contentRequirementsMet(Enrollment $enrollment, int $userId, int $lessonId): bool
    {
        return $this->unmetContentRequirements($enrollment, $userId, $lessonId) === [];
    }

    /**
     * Machine-readable codes for every unmet content requirement (empty when completable).
     *
     * @return list<string>
     */
    public function unmetContentRequirements(Enrollment $enrollment, int $userId, int $lessonId): array
    {
        $reasons = [];

        if ($this->assignments->hasRequired($lessonId) && ! $this->assignments->requiredSatisfied($lessonId, $userId)) {
            $reasons[] = 'assignment_required';
        }

        $required = $this->requiredBlocks->requiredBlockIds($lessonId);
        if ($required !== [] && ! $this->allBlocksCompleted($enrollment, $required)) {
            $reasons[] = 'blocks_incomplete';
        }

        return $reasons;
    }

    /** @param list<string> $requiredBlockIds */
    private function allBlocksCompleted(Enrollment $enrollment, array $requiredBlockIds): bool
    {
        $done = LearnerBlockProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('block_ref', $requiredBlockIds)
            ->distinct()
            ->count('block_ref');

        return $done >= count(array_unique($requiredBlockIds));
    }
}
