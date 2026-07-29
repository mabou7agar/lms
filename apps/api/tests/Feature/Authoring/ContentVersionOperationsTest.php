<?php

use App\Domains\Authoring\Enums\BlockType;
use App\Domains\Authoring\Enums\VersionReason;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\ContentVersion;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/** Live lessons for a course, ordered by section then position. */
function courseLessons(Course $course): Collection
{
    $sectionIds = Section::query()->where('course_id', $course->id)->orderBy('position')->pluck('id');

    return Lesson::query()->whereIn('section_id', $sectionIds)->orderBy('position')->get();
}

it('restore creates a safety snapshot then restores the full hierarchy and ordering', function () {
    $course = courseWithLessons(2);
    $v1 = versioning()->createSnapshot((int) $course->id, 1, 'v1', false);
    $originalFirstPublicId = $v1->snapshot['sections'][0]['lessons'][0]['public_id'];

    // Mutate the draft: add a third lesson.
    $section = Section::query()->where('course_id', $course->id)->firstOrFail();
    Lesson::factory()->create(['section_id' => $section->id, 'position' => 2, 'title' => 'Lesson 2']);
    expect(courseLessons($course))->toHaveCount(3);

    $safety = versioning()->restoreDraft($v1, 1);

    // Safety snapshot recorded the pre-restore (3-lesson) draft; the draft is back to v1 (2 lessons).
    expect($safety->reason)->toBe(VersionReason::Safety)
        ->and($safety->metadata['counts']['lessons'])->toBe(3)
        ->and(ContentVersion::forCourse((int) $course->id)->count())->toBe(2);

    $lessons = courseLessons($course);
    expect($lessons)->toHaveCount(2)
        ->and($lessons[0]->title)->toBe('Lesson 0')
        ->and($lessons[1]->title)->toBe('Lesson 1')
        // same-course restore preserves public_ids (stable external identity).
        ->and($lessons[0]->public_id)->toBe($originalFirstPublicId);

    // The historical version is never mutated.
    expect($v1->fresh()->metadata['counts']['lessons'])->toBe(2);
});

it('rollback creates a new version from an older one without touching later versions', function () {
    $course = courseWithLessons(2);
    $v1 = versioning()->createSnapshot((int) $course->id, 1, null, false);

    $section = Section::query()->where('course_id', $course->id)->firstOrFail();
    Lesson::factory()->create(['section_id' => $section->id, 'position' => 2, 'title' => 'Lesson 2']);
    $v2 = versioning()->createSnapshot((int) $course->id, 1, null, true);

    $v3 = versioning()->rollback($v1, 1, null);

    expect($v3->version_number)->toBe(3)
        ->and($v3->reason)->toBe(VersionReason::Rollback)
        ->and($v3->source_version_id)->toBe((int) $v1->id)
        ->and($v3->checksum)->toBe($v1->checksum)
        ->and(ContentVersion::forCourse((int) $course->id)->count())->toBe(3)
        ->and($v2->fresh())->not->toBeNull() // later version untouched
        ->and(courseLessons($course))->toHaveCount(2); // draft rolled back to v1
});

it('clone copies a version within the same course preserving source attribution', function () {
    $course = courseWithLessons(1);
    $v1 = versioning()->createSnapshot((int) $course->id, 1, null, false);

    $clone = versioning()->clone($v1, 1, 'My clone');

    expect($clone->version_number)->toBe(2)
        ->and($clone->reason)->toBe(VersionReason::Clone)
        ->and($clone->source_version_id)->toBe((int) $v1->id)
        ->and($clone->course_id)->toBe((int) $course->id)
        ->and($clone->checksum)->toBe($v1->checksum);
});

it('fork materialises into another course with fresh ids, no source foreign keys, and source attribution', function () {
    config()->set('authoring.blocks_enabled', true);

    $source = courseWithLessons(2);
    $section = Section::query()->where('course_id', $source->id)->firstOrFail();
    $sourceSectionPublicId = $section->public_id;
    $lesson = Lesson::query()->where('section_id', $section->id)->orderBy('position')->firstOrFail();
    $block = Block::factory()->for($lesson)->ofType(BlockType::Article)->create(['position' => 0]);

    $destination = Course::factory()->create();

    $sourceVersion = versioning()->createSnapshot((int) $source->id, 1, null, false);
    $fork = versioning()->fork($sourceVersion, (int) $destination->id, 1, 'Forked');

    // Fork attribution recorded on the destination version.
    expect($fork->reason)->toBe(VersionReason::Fork)
        ->and($fork->course_id)->toBe((int) $destination->id)
        ->and($fork->source_version_id)->toBe((int) $sourceVersion->id)
        ->and($fork->source_course_id)->toBe((int) $source->id);

    // Destination draft mirrors the structure with NEW ids and only destination course_id.
    $destSections = Section::query()->where('course_id', $destination->id)->get();
    expect($destSections)->toHaveCount(1)
        ->and($destSections[0]->public_id)->not->toBe($sourceSectionPublicId)
        ->and((int) $destSections[0]->course_id)->toBe((int) $destination->id);

    $destLessons = courseLessons($destination);
    expect($destLessons)->toHaveCount(2)
        ->and($destLessons[0]->assessment_id)->toBeNull(); // per-course ref dropped on fork

    $destBlock = Block::query()->where('lesson_id', $destLessons[0]->id)->first();
    expect($destBlock)->not->toBeNull()
        ->and($destBlock->public_id)->not->toBe($block->public_id); // remapped

    // Source course is untouched.
    expect(Section::query()->where('course_id', $source->id)->count())->toBe(1)
        ->and(Section::query()->where('course_id', $source->id)->first()->public_id)->toBe($sourceSectionPublicId);
});
