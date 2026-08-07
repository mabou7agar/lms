<?php

namespace App\Domains\Authoring\Actions\Lesson;

use App\Domains\Authoring\Actions\Concerns\GuardsLockVersion;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Platform\Shared\Actions\BaseAction;

class ReorderLessonsAction extends BaseAction
{
    use GuardsLockVersion;

    /**
     * Reordering a section's lessons is a mutation of that section's child-ordering, so the parent
     * SECTION is the optimistic-lock unit: its `lock_version` guards the ordering and advances on a
     * successful reorder. Locking the section row also serializes concurrent reorders of the same
     * section, keeping the final ordering deterministic and server-authoritative.
     *
     * @param  array<int, string>  $orderedPublicIds
     * @param  int|null  $expectedVersion  optimistic-lock guard (C3); null skips the check.
     * @return int the section's new lock_version
     */
    public function execute(Section $section, array $orderedPublicIds, ?int $expectedVersion = null): int
    {
        return $this->transaction(function () use ($section, $orderedPublicIds, $expectedVersion): int {
            $locked = Section::query()->whereKey($section->getKey())->lockForUpdate()->firstOrFail();

            $this->assertLockVersion($locked, $expectedVersion);

            foreach ($orderedPublicIds as $position => $publicId) {
                Lesson::where('section_id', $locked->id)
                    ->where('public_id', $publicId)
                    ->update(['position' => $position]);
            }

            $next = $this->advanceLockVersion($locked);
            $locked->save();

            return $next;
        });
    }
}
