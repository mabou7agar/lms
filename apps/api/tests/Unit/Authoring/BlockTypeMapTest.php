<?php

use App\Domains\Authoring\Enums\BlockFamily;
use App\Domains\Authoring\Enums\BlockType;
use App\Domains\Authoring\Enums\LessonType;
use App\Domains\Authoring\Support\BlockTypeMap;

/**
 * P2/W02 - Pure unit test (no DB). Proves the compatibility bridge is total and value-stable, and
 * that the backend block taxonomy is an EXACT, drift-free mirror of the frontend block-registry.
 */

/**
 * The exhaustive kind list from the frontend registry (src/lib/authoring/block-registry.ts,
 * BLOCK_DEFS). Kept here as the pinned contract; the two assertions below fail the moment the
 * backend enum and this list diverge in either direction.
 */
const FRONTEND_BLOCK_KINDS = [
    'article', 'pdf', 'download', 'external_link',
    'video', 'audio', 'live_session',
    'quiz_placeholder', 'quiz', 'assignment', 'survey',
    'scorm', 'xapi', 'cmi5',
    'discussion', 'certificate',
];

it('maps every legacy LessonType to a BlockType with an identical string value', function () {
    foreach (LessonType::cases() as $lessonType) {
        $block = BlockTypeMap::fromLessonType($lessonType);
        expect($block)->toBeInstanceOf(BlockType::class)
            ->and($block->value)->toBe($lessonType->value);
    }
});

it('assigns a family to every BlockType', function () {
    foreach (BlockType::cases() as $type) {
        expect($type->family())->toBeInstanceOf(BlockFamily::class);
    }
});

it('is a strict superset of the legacy lesson types', function () {
    $blockValues = array_map(fn (BlockType $t) => $t->value, BlockType::cases());
    foreach (LessonType::cases() as $lessonType) {
        expect($blockValues)->toContain($lessonType->value);
    }
});

it('mirrors the frontend block-registry taxonomy exactly (no drift, both directions)', function () {
    $blockValues = array_map(fn (BlockType $t) => $t->value, BlockType::cases());

    // Every frontend kind has a backend case...
    foreach (FRONTEND_BLOCK_KINDS as $kind) {
        expect($blockValues)->toContain($kind);
    }
    // ...and every backend case is a known frontend kind (no backend-only drift).
    foreach ($blockValues as $value) {
        expect(FRONTEND_BLOCK_KINDS)->toContain($value);
    }

    expect(count($blockValues))->toBe(count(FRONTEND_BLOCK_KINDS));
});
