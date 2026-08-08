<?php

namespace App\Domains\Authoring\Actions\Block;

use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Platform\Shared\Actions\BaseAction;

/**
 * C5 - Deep-copy a block within its own lesson: a fresh public_id, the full localized content map,
 * config, type and legacy payload mirror. The copy is appended at the end of the lesson
 * (position = max + 1) and reset to Draft — a duplicate is never published on creation. Copy
 * semantics mirror DuplicateLessonAction: the parent lesson row is locked to serialize the append.
 *
 * `learning_object_id` is a same-lesson-safe reference (a later Content Library object) and is
 * preserved on a within-lesson copy, exactly as DuplicateLessonAction preserves same-course refs.
 */
class DuplicateBlockAction extends BaseAction
{
    public function execute(Block $block, ?int $createdBy = null): Block
    {
        return $this->transaction(function () use ($block, $createdBy): Block {
            $lessonId = (int) $block->lesson_id;

            Lesson::whereKey($lessonId)->lockForUpdate()->first();

            $max = Block::where('lesson_id', $lessonId)->max('position');
            $position = $max === null ? 0 : (int) $max + 1;

            $copy = new Block([
                'type' => $block->type->value,
                'content_i18n' => $block->content_i18n,
                'config' => $block->config,
                'position' => $position,
            ]);
            $copy->lesson_id = $lessonId;
            $copy->publish_state = PublishState::Draft->value;
            $copy->created_by = $createdBy;
            $copy->payload = $block->payload;
            $copy->learning_object_id = $block->learning_object_id;
            $copy->save(); // saving hook derives family from type

            return $copy;
        });
    }
}
