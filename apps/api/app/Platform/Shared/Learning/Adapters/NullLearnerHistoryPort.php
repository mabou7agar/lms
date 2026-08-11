<?php

declare(strict_types=1);

namespace App\Platform\Shared\Learning\Adapters;

use App\Platform\Shared\Learning\Contracts\LearnerHistoryPort;

/**
 * Default LearnerHistoryPort: empty history. Bound by Catalog so RecommendationService always
 * resolves, even when the Learning context is not loaded. Learning overrides this with a real
 * adapter reading its enrollment read model (integration step).
 */
final class NullLearnerHistoryPort implements LearnerHistoryPort
{
    public function completedCourseIds(int $userId): array
    {
        return [];
    }

    public function inProgressCourseIds(int $userId): array
    {
        return [];
    }

    public function enrolledCourseIds(int $userId): array
    {
        return [];
    }
}
