<?php

namespace App\Domains\Authoring\Services;

use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Support\BlockTypeMap;

/**
 * P2/W02 - Idempotent backfill: wraps each existing Lesson as a single Content Block mirroring its
 * type + content, WITHOUT touching the lesson. Safe to re-run: lessons that already have a block
 * (including a soft-deleted one) are skipped. Not auto-registered and not run by any request path;
 * invoked explicitly (tinker/command) and only when `authoring.blocks_enabled` is on.
 */
final class BlockBackfillService
{
    /** @return int number of blocks created this run */
    public function run(): int
    {
        // Real feature-flag enforcement: this is the only path that writes blocks today, so gating
        // it makes `authoring.blocks_enabled` load-bearing. Off (the default) => no-op.
        if (! (bool) config('authoring.blocks_enabled', false)) {
            return 0;
        }

        $created = 0;

        Lesson::query()
            // withTrashed() so a soft-deleted block still marks its lesson as backfilled — a re-run
            // must never create a duplicate seed block. The DB partial-unique index on
            // (lesson_id, position) is the concurrency backstop.
            ->whereNotIn('id', Block::withTrashed()->select('lesson_id'))
            ->chunkById(200, function ($lessons) use (&$created): void {
                foreach ($lessons as $lesson) {
                    $type = BlockTypeMap::fromLessonType($lesson->type);

                    // Only presentation fields are mass-assignable; the rest are set explicitly.
                    $block = new Block([
                        'type' => $type,
                        'payload' => $lesson->content,
                        'position' => 0,
                    ]);
                    $block->lesson_id = $lesson->getKey();
                    $block->publish_state = $lesson->publish_state;
                    $block->save(); // saving hook derives family from type

                    $created++;
                }
            });

        return $created;
    }
}
