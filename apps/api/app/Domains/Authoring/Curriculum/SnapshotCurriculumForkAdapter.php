<?php

namespace App\Domains\Authoring\Curriculum;

use App\Domains\Authoring\Snapshots\SnapshotRestorer;
use App\Domains\Authoring\Snapshots\SnapshotSerializer;
use App\Platform\Shared\Curriculum\Contracts\CurriculumForkPort;
use Illuminate\Support\Facades\DB;

/**
 * Authoring implementation of the Shared CurriculumForkPort. Reuses the EXACT fork materialisation
 * that ContentVersioningService::fork performs — capture the source course's authoring subtree, then
 * restore it into the target course with regenerated identifiers (fresh public_ids, the destination
 * course_id, no source foreign keys) — without minting a ContentVersion row, which would be
 * meaningless for a catalog-level duplicate and would pollute the source's version history.
 *
 * Bound in AuthoringServiceProvider, overriding Catalog's NullCurriculumForkPort default. Keeping this
 * in Authoring means Catalog never imports Authoring: the deptrac boundary holds (Catalog -> Shared
 * contract only).
 */
final class SnapshotCurriculumForkAdapter implements CurriculumForkPort
{
    public function __construct(
        private readonly SnapshotSerializer $serializer,
        private readonly SnapshotRestorer $restorer,
    ) {}

    public function fork(int $sourceCourseId, int $targetCourseId): void
    {
        // The restorer requires a transaction boundary; nest safely under any outer transaction.
        DB::transaction(function () use ($sourceCourseId, $targetCourseId): void {
            $snapshot = $this->serializer->capture($sourceCourseId);
            $this->restorer->restore($targetCourseId, $snapshot, regenerateIds: true);
        });
    }
}
