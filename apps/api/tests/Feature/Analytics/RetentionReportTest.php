<?php

use App\Contexts\Analytics\Services\Reports\ReportingService;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonProgress;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * C5 — retention rewritten from load-everything-into-PHP to bounded grouped SQL. This pins that the
 * cohort definition is preserved exactly: cohort = month of first enrollment; retained = a completed
 * lesson strictly after the cohort month.
 */
function enrolAtDate(User $user, Course $course, string $enrolledAt): Enrollment
{
    $enrollment = Enrollment::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);
    DB::table('enrollments')->where('id', $enrollment->id)->update(['enrolled_at' => $enrolledAt]);

    return $enrollment->refresh();
}

function completeLessonAt(Enrollment $enrollment, Lesson $lesson, string $completedAt): void
{
    $progress = LessonProgress::factory()->completed()->create([
        'enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id,
    ]);
    DB::table('lesson_progress')->where('id', $progress->id)->update(['completed_at' => $completedAt]);
}

it('groups cohorts by first-enrollment month and marks later activity as retained', function () {
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['section_id' => $section->id]);

    // January cohort: A is retained (activity in Feb), B is not (no later activity).
    $a = enrolAtDate(User::factory()->create(), $course, '2024-01-05 10:00:00');
    completeLessonAt($a, $lesson, '2024-02-10 09:00:00');
    enrolAtDate(User::factory()->create(), $course, '2024-01-20 10:00:00');

    // February cohort: C, not retained.
    enrolAtDate(User::factory()->create(), $course, '2024-02-03 10:00:00');

    $result = app(ReportingService::class)->retention(
        CarbonImmutable::parse('2024-01-01')->startOfDay(),
        CarbonImmutable::parse('2024-02-29')->endOfDay(),
    );

    expect($result['cohorts'])->toBe([
        ['cohort' => '2024-01', 'cohort_size' => 2, 'retained' => 1, 'retention_rate' => 50.0],
        ['cohort' => '2024-02', 'cohort_size' => 1, 'retained' => 0, 'retention_rate' => 0.0],
    ]);
});

it('excludes cohorts whose first enrollment falls outside the window', function () {
    $course = Course::factory()->create();

    // First enrollment in December — before the window — must not appear.
    enrolAtDate(User::factory()->create(), $course, '2023-12-15 10:00:00');
    // First enrollment inside the window.
    enrolAtDate(User::factory()->create(), $course, '2024-01-10 10:00:00');

    $result = app(ReportingService::class)->retention(
        CarbonImmutable::parse('2024-01-01')->startOfDay(),
        CarbonImmutable::parse('2024-01-31')->endOfDay(),
    );

    expect($result['cohorts'])->toBe([
        ['cohort' => '2024-01', 'cohort_size' => 1, 'retained' => 0, 'retention_rate' => 0.0],
    ]);
});

it('excludes soft-deleted enrollments from cohort size and retention', function () {
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['section_id' => $section->id]);

    // Active learner in the January cohort, retained by a February completion.
    $active = enrolAtDate(User::factory()->create(), $course, '2024-01-05 10:00:00');
    completeLessonAt($active, $lesson, '2024-02-10 09:00:00');

    // A revoked/refunded (soft-deleted) enrollment must not inflate the cohort. Eloquent soft delete
    // sets deleted_at; the raw SQL must honour that the same way the model's global scope would.
    $revoked = enrolAtDate(User::factory()->create(), $course, '2024-01-20 10:00:00');
    $revoked->delete();

    $result = app(ReportingService::class)->retention(
        CarbonImmutable::parse('2024-01-01')->startOfDay(),
        CarbonImmutable::parse('2024-02-29')->endOfDay(),
    );

    expect($result['cohorts'])->toBe([
        ['cohort' => '2024-01', 'cohort_size' => 1, 'retained' => 1, 'retention_rate' => 100.0],
    ]);
});

it('marks a first-of-next-month completion retained and a same-month completion not', function () {
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['section_id' => $section->id]);

    // Retained: enrolled on the last second of January, completed at the first instant of February.
    // The SQL threshold is date_trunc('month', fe) + 1 month = 2024-02-01 00:00:00, and la >= it.
    $boundary = enrolAtDate(User::factory()->create(), $course, '2024-01-31 23:59:59');
    completeLessonAt($boundary, $lesson, '2024-02-01 00:00:00');

    // Not retained: activity within the same cohort month is strictly before the next-month threshold.
    $sameMonth = enrolAtDate(User::factory()->create(), $course, '2024-01-10 10:00:00');
    completeLessonAt($sameMonth, $lesson, '2024-01-31 23:59:59');

    $result = app(ReportingService::class)->retention(
        CarbonImmutable::parse('2024-01-01')->startOfDay(),
        CarbonImmutable::parse('2024-01-31')->endOfDay(),
    );

    expect($result['cohorts'])->toBe([
        ['cohort' => '2024-01', 'cohort_size' => 2, 'retained' => 1, 'retention_rate' => 50.0],
    ]);
});
