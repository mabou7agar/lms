<?php

namespace App\Platform\Shared\Curriculum\Adapters;

use App\Platform\Shared\Curriculum\Contracts\CurriculumForkPort;

/**
 * Default no-op CurriculumForkPort. Bound by Catalog so a course can always be duplicated even when
 * the Authoring context is not loaded; the real snapshot-fork adapter (Authoring) overrides this
 * binding when present. With the null port, curriculum copy is deferred (catalog record +
 * associations only), never an error.
 */
final class NullCurriculumForkPort implements CurriculumForkPort
{
    public function fork(int $sourceCourseId, int $targetCourseId): void
    {
        // Intentionally empty: no curriculum context available to fork.
    }
}
