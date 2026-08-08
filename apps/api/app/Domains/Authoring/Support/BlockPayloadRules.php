<?php

namespace App\Domains\Authoring\Support;

use App\Domains\Authoring\Enums\BlockType;

/**
 * C5 - Typed, closed payload schema per runtime-supported BlockType. This is what makes a block's
 * localized `content_i18n` a controlled shape rather than an arbitrary JSON blob: each locale's
 * payload is validated against these rules AND rejected if it carries any key not listed here.
 *
 * Rules are plain Laravel validation arrays so they compose with a sub-Validator per locale (see
 * ValidatesBlockPayload). Unsupported types return an empty schema; they never reach here because
 * the request layer rejects them against SupportedBlockTypes first.
 */
final class BlockPayloadRules
{
    /**
     * @return array<string, array<int, string>> field => rules
     */
    public static function forType(BlockType $type): array
    {
        return match ($type) {
            // rich text
            BlockType::Article => [
                'html' => ['required', 'string'],
            ],
            // file / downloadable resource — a stored object reference and/or a resolvable URL
            BlockType::Pdf, BlockType::Download => [
                's3_key' => ['nullable', 'string', 'max:1024'],
                'filename' => ['nullable', 'string', 'max:255'],
                'url' => ['nullable', 'string', 'max:2048'],
            ],
            // embed / external link
            BlockType::ExternalLink => [
                'url' => ['required', 'string', 'max:2048'],
                'label' => ['nullable', 'string', 'max:255'],
            ],
            // media — playback reference (Mux) and/or storage key and/or URL
            BlockType::Video, BlockType::Audio => [
                'mux_playback_id' => ['nullable', 'string', 'max:255'],
                's3_key' => ['nullable', 'string', 'max:1024'],
                'url' => ['nullable', 'string', 'max:2048'],
            ],
            // inert authored quiz text
            BlockType::QuizPlaceholder => [
                'note' => ['nullable', 'string', 'max:5000'],
            ],
            // assessment reference — points at an Assessment public_id (resolved elsewhere)
            BlockType::Quiz => [
                'assessment_public_id' => ['nullable', 'string', 'uuid'],
            ],
            default => [],
        };
    }

    /** @return list<string> the only keys a payload of this type may contain */
    public static function allowedKeys(BlockType $type): array
    {
        return array_keys(self::forType($type));
    }
}
