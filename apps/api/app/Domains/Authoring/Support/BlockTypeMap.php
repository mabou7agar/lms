<?php

namespace App\Domains\Authoring\Support;

use App\Domains\Authoring\Enums\BlockType;
use App\Domains\Authoring\Enums\LessonType;

/**
 * P2/W02 - Compatibility bridge: every legacy LessonType maps to exactly one BlockType, so the
 * existing Lesson.type surface remains the source of truth while Blocks are introduced. Pure and
 * total (no DB, no side effects) - the backbone of the "no breaking change" guarantee.
 */
final class BlockTypeMap
{
    public static function fromLessonType(LessonType $type): BlockType
    {
        return match ($type) {
            LessonType::Video => BlockType::Video,
            LessonType::Audio => BlockType::Audio,
            LessonType::Article => BlockType::Article,
            LessonType::Pdf => BlockType::Pdf,
            LessonType::Download => BlockType::Download,
            LessonType::ExternalLink => BlockType::ExternalLink,
            LessonType::QuizPlaceholder => BlockType::QuizPlaceholder,
            LessonType::Quiz => BlockType::Quiz,
        };
    }
}
