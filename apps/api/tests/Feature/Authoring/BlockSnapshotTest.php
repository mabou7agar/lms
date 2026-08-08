<?php

use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Authoring\Services\ContentVersioningService;
use App\Domains\Authoring\Snapshots\SnapshotSerializer;
use App\Domains\Catalog\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

if (! function_exists('blockSnapshotCourse')) {
    function blockSnapshotCourse(): array
    {
        $course = Course::factory()->create();
        $section = Section::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create(['section_id' => $section->id, 'position' => 0]);

        return [$course, $lesson];
    }
}

it('captures block content_i18n and config in a snapshot when the flag is on', function () {
    config()->set('authoring.blocks_enabled', true);
    [$course, $lesson] = blockSnapshotCourse();

    Block::factory()->for($lesson)->ofType(\App\Domains\Authoring\Enums\BlockType::Article)->create([
        'position' => 0,
        'content_i18n' => ['en' => ['html' => '<p>EN</p>'], 'ar' => ['html' => '<p>AR</p>']],
        'config' => ['collapsed' => true],
    ]);

    $version = app(ContentVersioningService::class)->createSnapshot((int) $course->id, 1, 'v1', false);

    $snapshotBlock = $version->snapshot['sections'][0]['lessons'][0]['blocks'][0];
    expect($version->metadata['counts']['blocks'])->toBe(1)
        // jsonb locale maps are order-insensitive: assert value equality, not identical key order.
        ->and($snapshotBlock['content_i18n'])->toEqual(['en' => ['html' => '<p>EN</p>'], 'ar' => ['html' => '<p>AR</p>']])
        ->and($snapshotBlock['config'])->toBe(['collapsed' => true])
        // lock_version is intentionally NOT part of the immutable snapshot.
        ->and($snapshotBlock)->not->toHaveKey('lock_version')
        // the snapshot verifies against its own checksum.
        ->and(SnapshotSerializer::checksum($version->snapshot))->toBe($version->checksum);
});

it('restores blocks (content_i18n + config) from a version, replacing the current draft', function () {
    config()->set('authoring.blocks_enabled', true);
    [$course, $lesson] = blockSnapshotCourse();

    Block::factory()->for($lesson)->ofType(\App\Domains\Authoring\Enums\BlockType::Article)->create([
        'position' => 0,
        'content_i18n' => ['en' => ['html' => '<p>original</p>']],
        'config' => ['k' => 'v'],
    ]);

    $versioning = app(ContentVersioningService::class);
    $version = $versioning->createSnapshot((int) $course->id, 1, 'v1', false);

    // Destroy the entire block layer (and its lesson/section) after snapshotting.
    Block::query()->forceDelete();
    Section::query()->where('course_id', $course->id)->forceDelete();
    expect(Block::count())->toBe(0);

    // Restore the version -> the block comes back with its localized content and config intact.
    $versioning->restoreDraft($version, 1);

    $restored = Block::query()->firstOrFail();
    expect(Block::count())->toBe(1)
        ->and($restored->content_i18n)->toBe(['en' => ['html' => '<p>original</p>']])
        ->and($restored->config)->toBe(['k' => 'v'])
        ->and($restored->position)->toBe(0)
        // a rebuilt block starts at the default lock_version.
        ->and($restored->lock_version)->toBe(0);
});
