<?php

use App\Domains\Authoring\Exceptions\SnapshotChecksumMismatchException;
use App\Domains\Authoring\Exceptions\UnsupportedSnapshotSchemaException;
use App\Domains\Authoring\Models\ContentVersion;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Authoring\Snapshots\SnapshotSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects a version whose stored snapshot fails its checksum', function () {
    $course = courseWithLessons(1);

    $valid = ['schema_version' => 1, 'course_id' => (int) $course->id, 'modules' => [], 'sections' => []];
    $tampered = ContentVersion::factory()->create([
        'course_id' => $course->id,
        'snapshot' => $valid,
        'snapshot_schema_version' => 1,
        'checksum' => str_repeat('0', 64), // does not match snapshot
    ]);

    expect(fn () => versioning()->restoreDraft($tampered, 1))
        ->toThrow(SnapshotChecksumMismatchException::class);

    // Integrity is checked BEFORE the transaction: no safety snapshot leaked.
    expect(ContentVersion::forCourse((int) $course->id)->count())->toBe(1);
});

it('rejects an unsupported snapshot schema and rolls the whole operation back atomically', function () {
    $course = courseWithLessons(2);

    $bad = ['schema_version' => 999, 'course_id' => (int) $course->id, 'modules' => [], 'sections' => []];
    $version = ContentVersion::factory()->create([
        'course_id' => $course->id,
        'snapshot' => $bad,
        'snapshot_schema_version' => 999,
        'checksum' => SnapshotSerializer::checksum($bad), // integrity passes; schema is the problem
    ]);

    expect(fn () => versioning()->restoreDraft($version, 1))
        ->toThrow(UnsupportedSnapshotSchemaException::class);

    // The safety snapshot written inside the transaction must have been rolled back, and the live
    // draft must be untouched.
    expect(ContentVersion::forCourse((int) $course->id)->count())->toBe(1)
        ->and(Section::query()->where('course_id', $course->id)->count())->toBe(1)
        ->and(Lesson::query()->whereIn('section_id', Section::query()->where('course_id', $course->id)->pluck('id'))->count())->toBe(2);
});
