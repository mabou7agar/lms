<?php

namespace App\Domains\Authoring\Support;

use App\Domains\Authoring\Enums\BlockType;

/**
 * C5 - The set of BlockTypes the learner runtime actually renders today, and therefore the ONLY
 * types the authoring block API exposes for create/edit.
 *
 * This mirrors the frontend block-registry's `supported: true` flags one-for-one
 * (src/lib/authoring/block-registry.ts). Every type here also has a legacy LessonType (see
 * BlockTypeMap), which is precisely why the runtime renders it. Types that are design-complete on
 * the frontend but not yet renderable (live_session, assignment, survey, scorm, xapi, cmi5,
 * discussion, certificate) are deliberately excluded — no invented support.
 *
 * Conceptual mapping to the runtime capabilities:
 *   rich text            -> Article
 *   file                 -> Pdf
 *   downloadable resource -> Download
 *   embed / external     -> ExternalLink
 *   video                -> Video
 *   audio                -> Audio
 *   quiz (inert)         -> QuizPlaceholder
 *   assessment reference -> Quiz
 */
final class SupportedBlockTypes
{
    /** @return list<BlockType> */
    public static function all(): array
    {
        return [
            BlockType::Article,
            BlockType::Pdf,
            BlockType::Download,
            BlockType::ExternalLink,
            BlockType::Video,
            BlockType::Audio,
            BlockType::QuizPlaceholder,
            BlockType::Quiz,
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (BlockType $t): string => $t->value, self::all());
    }

    public static function supports(BlockType $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
