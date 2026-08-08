<?php

namespace App\Platform\Shared\Curriculum\Contracts;

/**
 * Cross-context port for materialising one course's curriculum (sections/lessons/blocks/media) into
 * ANOTHER course with fresh identifiers. Curriculum is owned by Authoring, so Catalog (which owns the
 * Course record) copies the catalog-level associations itself and delegates the curriculum copy to
 * this port — never importing Authoring directly (deptrac boundary).
 *
 * The Authoring implementation reuses the existing snapshot fork mechanism (fresh public_ids, the
 * destination course_id, no source foreign keys). When no implementation is bound (standalone
 * Catalog), the null default is a no-op and curriculum copy is simply deferred.
 */
interface CurriculumForkPort
{
    /** Copy the source course's curriculum into the target course with regenerated identifiers. */
    public function fork(int $sourceCourseId, int $targetCourseId): void;
}
