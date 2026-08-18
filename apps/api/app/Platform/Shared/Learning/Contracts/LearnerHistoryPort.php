<?php

declare(strict_types=1);

namespace App\Platform\Shared\Learning\Contracts;

use App\Platform\Shared\Learning\Adapters\NullLearnerHistoryPort;

/**
 * Read-only projection of a learner's course history for RECOMMENDATIONS. Declared in Shared and
 * implemented by the context that owns enrollments (Learning); Catalog's RecommendationService only
 * consumes it, so recommendations never read Learning's tables directly.
 *
 * The default {@see NullLearnerHistoryPort} returns empty
 * history, so the "continue learning" / "next course" surfaces are simply empty until Learning binds
 * a real adapter — the deterministic category/tag recommendations do not depend on it.
 *
 * All ids are internal course ids, already tenant-scoped by the implementer.
 */
interface LearnerHistoryPort
{
    /**
     * Courses the learner has completed, most-recent first.
     *
     * @return list<int>
     */
    public function completedCourseIds(int $userId): array;

    /**
     * Courses the learner is actively enrolled in but has NOT completed, most-recent first.
     *
     * @return list<int>
     */
    public function inProgressCourseIds(int $userId): array;

    /**
     * Every course the learner has any enrollment in (active or completed). Used to exclude
     * already-taken courses from "next course" suggestions.
     *
     * @return list<int>
     */
    public function enrolledCourseIds(int $userId): array;
}
