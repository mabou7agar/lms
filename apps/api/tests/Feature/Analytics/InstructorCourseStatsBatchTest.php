<?php

use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Services\InstructorAnalyticsService;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Sprint 8 — M6 (courseStats routed through the canonical EnrollmentStatsPort) and H3 (the deferred
 * batched instructor list). These pin that the numbers are unchanged and that the aggregates are
 * batched rather than issued per course.
 */
function courseWithEnrollments(int $completed, int $active, int $lessons = 2): Course
{
    $course = Course::factory()->published()->create();
    $section = Section::factory()->create(['course_id' => $course->id, 'position' => 0]);
    for ($i = 0; $i < $lessons; $i++) {
        Lesson::factory()->create(['section_id' => $section->id, 'position' => $i]);
    }

    for ($i = 0; $i < $completed; $i++) {
        Enrollment::factory()->create([
            'user_id' => User::factory()->create()->id, 'course_id' => $course->id,
            'status' => EnrollmentStatus::Completed->value, 'progress_percentage' => 100,
        ]);
    }
    for ($i = 0; $i < $active; $i++) {
        Enrollment::factory()->create([
            'user_id' => User::factory()->create()->id, 'course_id' => $course->id,
            'status' => EnrollmentStatus::Active->value, 'progress_percentage' => 50,
        ]);
    }

    return $course;
}

it('reports the same figures after routing courseStats through the enrollment port (M6)', function () {
    // 1 completed (100%) + 2 active (50%) → avg = round((100+50+50)/3) = 67.
    $course = courseWithEnrollments(completed: 1, active: 2, lessons: 2);

    expect(app(InstructorAnalyticsService::class)->courseStats($course))->toBe([
        'enrollments' => 3,
        'completions' => 1,
        'avg_progress' => 67,
        'sections' => 1,
        'lessons' => 2,
        'assessment_pass_rate' => null,
        'graded_attempts' => 0,
    ]);
});

it('returns byte-identical per-course stats whether computed singly or batched (H3)', function () {
    $courses = collect([
        courseWithEnrollments(2, 1),
        courseWithEnrollments(0, 3),
        courseWithEnrollments(1, 0),
    ]);

    $svc = app(InstructorAnalyticsService::class);

    $single = $courses->mapWithKeys(fn (Course $c) => [(int) $c->getKey() => $svc->courseStats($c)])->all();
    $batched = $svc->courseStatsForCourses($courses);

    expect($batched)->toEqual($single);
});

it('batches the enrollment and pass-rate aggregates instead of a query per course (H3)', function () {
    $courses = collect([courseWithEnrollments(1, 1), courseWithEnrollments(1, 1), courseWithEnrollments(1, 1)]);
    $svc = app(InstructorAnalyticsService::class);

    // Warm nothing: measure the per-course loop vs the batched call over the same courses.
    DB::enableQueryLog();
    $courses->each(fn (Course $c) => $svc->courseStats($c));
    $looped = count(DB::getQueryLog());
    DB::flushQueryLog();

    $svc->courseStatsForCourses($courses);
    $batched = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The batched path folds the enrollment aggregate and the quiz pass-rate into one query each for
    // the whole set, so it must issue strictly fewer queries than N separate courseStats() calls.
    expect($batched)->toBeLessThan($looped);
});
