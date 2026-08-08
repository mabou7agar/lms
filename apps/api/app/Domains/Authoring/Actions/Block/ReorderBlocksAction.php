<?php

namespace App\Domains\Authoring\Actions\Block;

use App\Domains\Authoring\Actions\Concerns\GuardsLockVersion;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Support\Facades\DB;

/**
 * C5 - Reorder / move blocks within a lesson, server-authoritatively.
 *
 * Reordering a lesson's blocks is a mutation of that lesson's child-ordering, so the parent LESSON
 * is the optimistic-lock unit — its `lock_version` guards the ordering and advances on success —
 * exactly as ReorderLessonsAction uses the parent SECTION for lesson ordering. Locking the lesson
 * row also serializes concurrent reorders, so the final ordering is deterministic (last committed
 * writer wins) with a clean, contiguous 0..n-1 sequence.
 *
 * Because content_blocks carries a partial-UNIQUE (lesson_id, position) index over live rows, a
 * naive per-row reassignment could transiently collide. The reorder is therefore two-phase: shift
 * every live block out of the target range in one statement, then assign the requested contiguous
 * positions. Neither statement ever leaves a duplicate live (lesson_id, position) pair.
 */
class ReorderBlocksAction extends BaseAction
{
    use GuardsLockVersion;

    private const SHIFT = 1_000_000;

    /**
     * @param  array<int, string>  $orderedPublicIds  the complete ordered set of the lesson's block public_ids
     * @param  int|null  $expectedVersion  optimistic-lock guard (C3); null skips the check.
     * @return int the lesson's new lock_version
     */
    public function execute(Lesson $lesson, array $orderedPublicIds, ?int $expectedVersion = null): int
    {
        return $this->transaction(function () use ($lesson, $orderedPublicIds, $expectedVersion): int {
            $locked = Lesson::query()->whereKey($lesson->getKey())->lockForUpdate()->firstOrFail();

            $this->assertLockVersion($locked, $expectedVersion);

            // Phase 1: shift all live blocks out of the 0..n-1 target range in a single statement so
            // the partial-unique index is never transiently violated. A uniform shift of an
            // already-distinct set stays distinct.
            Block::where('lesson_id', $locked->id)->update([
                'position' => DB::raw('position + '.self::SHIFT),
            ]);

            // Phase 2: assign the requested contiguous positions authoritatively.
            foreach ($orderedPublicIds as $position => $publicId) {
                Block::where('lesson_id', $locked->id)
                    ->where('public_id', $publicId)
                    ->update(['position' => $position]);
            }

            $next = $this->advanceLockVersion($locked);
            $locked->save();

            return $next;
        });
    }
}
