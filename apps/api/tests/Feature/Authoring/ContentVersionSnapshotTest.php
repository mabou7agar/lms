<?php

use App\Domains\Authoring\Enums\BlockType;
use App\Domains\Authoring\Enums\VersionReason;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\ContentVersion;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Authoring\Services\ContentVersioningService;
use App\Domains\Catalog\Models\Course;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

if (! function_exists('courseWithLessons')) {
    function courseWithLessons(int $lessons = 2): Course
    {
        $course = Course::factory()->create();
        $section = Section::factory()->create(['course_id' => $course->id]);
        for ($i = 0; $i < $lessons; $i++) {
            Lesson::factory()->create(['section_id' => $section->id, 'position' => $i, 'title' => "Lesson {$i}"]);
        }

        return $course;
    }
}

if (! function_exists('versioning')) {
    function versioning(): ContentVersioningService
    {
        return app(ContentVersioningService::class);
    }
}

it('creates a first snapshot with version number 1, checksum and counts', function () {
    $course = courseWithLessons(2);

    $v = versioning()->createSnapshot((int) $course->id, 1, 'Initial', false);

    expect($v->version_number)->toBe(1)
        ->and($v->reason)->toBe(VersionReason::Manual)
        ->and($v->checksum)->toHaveLength(64)
        ->and($v->metadata['counts']['sections'])->toBe(1)
        ->and($v->metadata['counts']['lessons'])->toBe(2)
        ->and($v->created_by)->toBe(1);
});

it('deduplicates an unchanged snapshot unless forced', function () {
    $course = courseWithLessons(1);

    $first = versioning()->createSnapshot((int) $course->id, 1, null, false);
    $again = versioning()->createSnapshot((int) $course->id, 1, null, false);

    expect($again->id)->toBe($first->id)
        ->and(ContentVersion::forCourse((int) $course->id)->count())->toBe(1);

    $forced = versioning()->createSnapshot((int) $course->id, 1, null, true);
    expect($forced->version_number)->toBe(2)
        ->and(ContentVersion::forCourse((int) $course->id)->count())->toBe(2);
});

it('numbers versions monotonically per course', function () {
    $course = courseWithLessons(1);
    versioning()->createSnapshot((int) $course->id, 1, null, true);
    versioning()->createSnapshot((int) $course->id, 1, null, true);
    $third = versioning()->createSnapshot((int) $course->id, 1, null, true);

    expect($third->version_number)->toBe(3);
});

it('captures only live (non-soft-deleted) content', function () {
    $course = courseWithLessons(2);
    Lesson::query()->where('title', 'Lesson 1')->first()->delete(); // soft delete

    $v = versioning()->createSnapshot((int) $course->id, 1, null, false);

    expect($v->metadata['counts']['lessons'])->toBe(1);
});

it('omits blocks when the blocks feature flag is off and includes them when on', function () {
    $course = courseWithLessons(1);
    $lesson = Lesson::query()->firstOrFail();
    Block::factory()->for($lesson)->ofType(BlockType::Article)->create(['position' => 0]);

    config()->set('authoring.blocks_enabled', false);
    $off = versioning()->createSnapshot((int) $course->id, 1, null, true);
    expect($off->metadata['counts']['blocks'])->toBe(0);

    config()->set('authoring.blocks_enabled', true);
    $on = versioning()->createSnapshot((int) $course->id, 1, null, true);
    expect($on->metadata['counts']['blocks'])->toBe(1)
        ->and($on->snapshot['sections'][0]['lessons'][0]['blocks'])->toHaveCount(1);
});

it('cannot be updated or deleted (immutable at the model layer)', function () {
    $course = courseWithLessons(1);
    $v = versioning()->createSnapshot((int) $course->id, 1, null, false);

    expect(fn () => $v->update(['label' => 'changed']))->toThrow(RuntimeException::class)
        ->and(fn () => $v->delete())->toThrow(RuntimeException::class);
});

it('cannot be mutated at the database layer (immutability trigger)', function () {
    $course = courseWithLessons(1);
    $v = versioning()->createSnapshot((int) $course->id, 1, null, false);

    expect(fn () => DB::table('content_versions')->where('id', $v->id)->update(['label' => 'x']))
        ->toThrow(QueryException::class);
});

it('enforces unique version numbers per course at the database layer', function () {
    $course = courseWithLessons(1);
    $v = versioning()->createSnapshot((int) $course->id, 1, null, false);

    expect(fn () => DB::table('content_versions')->insert([
        'public_id' => (string) Str::uuid(),
        'course_id' => $course->id,
        'version_number' => $v->version_number, // duplicate
        'reason' => 'manual',
        'snapshot' => json_encode(['schema_version' => 1, 'course_id' => (int) $course->id, 'modules' => [], 'sections' => []]),
        'snapshot_schema_version' => 1,
        'checksum' => str_repeat('a', 64),
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});
