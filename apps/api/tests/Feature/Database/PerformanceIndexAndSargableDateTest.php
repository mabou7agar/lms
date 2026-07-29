<?php

use App\Contexts\Learning\Analytics\EnrollmentStatsAdapter;
use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
});

/**
 * Sprint 6 — M5 sargable date filters. These pin the EXACT day-boundary semantics the half-open
 * range must preserve versus the old whereDate(), so the performance change cannot silently move a
 * record in or out of a window.
 */
function enrolAt(Course $course, string $enrolledAtUtc, string $status = 'active'): void
{
    $user = User::factory()->create();

    // Set enrolled_at precisely, bypassing Eloquent's timestamp touching.
    $enrollment = Enrollment::factory()->create([
        'user_id' => $user->id,
        'course_id' => $course->id,
        'status' => $status,
    ]);

    DB::table('enrollments')->where('id', $enrollment->id)->update(['enrolled_at' => $enrolledAtUtc]);
}

function statsFor(Course $course, string $from, string $to)
{
    return app(EnrollmentStatsAdapter::class)->statsForCourses([$course->id], $from, $to);
}

it('includes a record exactly at the start of the from day', function () {
    $course = Course::factory()->create();
    enrolAt($course, '2026-07-01 00:00:00');

    expect(statsFor($course, '2026-07-01', '2026-07-31')->enrollments)->toBe(1);
});

it('excludes a record the instant before the from day', function () {
    $course = Course::factory()->create();
    enrolAt($course, '2026-06-30 23:59:59');

    expect(statsFor($course, '2026-07-01', '2026-07-31')->enrollments)->toBe(0);
});

it('includes a record the instant before the day after the to day', function () {
    $course = Course::factory()->create();
    enrolAt($course, '2026-07-31 23:59:59');

    // The old whereDate('<=', '2026-07-31') included all of the 31st; the half-open range must too.
    expect(statsFor($course, '2026-07-01', '2026-07-31')->enrollments)->toBe(1);
});

it('excludes a record exactly at the start of the day after the to day', function () {
    $course = Course::factory()->create();
    enrolAt($course, '2026-08-01 00:00:00');

    expect(statsFor($course, '2026-07-01', '2026-07-31')->enrollments)->toBe(0);
});

it('keeps courses isolated within a window', function () {
    $a = Course::factory()->create();
    $b = Course::factory()->create();
    enrolAt($a, '2026-07-10 12:00:00');
    enrolAt($b, '2026-07-10 12:00:00');

    expect(statsFor($a, '2026-07-01', '2026-07-31')->enrollments)->toBe(1);
});

it('returns an empty aggregate when nothing falls in the window', function () {
    $course = Course::factory()->create();
    enrolAt($course, '2026-05-01 12:00:00');

    $stats = statsFor($course, '2026-07-01', '2026-07-31');

    expect($stats->enrollments)->toBe(0)
        ->and($stats->completions)->toBe(0)
        ->and($stats->completionRate())->toBeNull();
});

it('preserves aggregate values unchanged across the window', function () {
    $course = Course::factory()->create();
    enrolAt($course, '2026-07-05 09:00:00', EnrollmentStatus::Completed->value);
    enrolAt($course, '2026-07-06 09:00:00', EnrollmentStatus::Active->value);
    enrolAt($course, '2026-06-01 09:00:00', EnrollmentStatus::Completed->value); // outside window

    $stats = statsFor($course, '2026-07-01', '2026-07-31');

    expect($stats->enrollments)->toBe(2)
        ->and($stats->completions)->toBe(1)
        ->and($stats->completionRate())->toBe(50);
});

it('does not wrap the date column in a SQL date function', function () {
    $course = Course::factory()->create();
    enrolAt($course, '2026-07-10 12:00:00');

    DB::enableQueryLog();
    statsFor($course, '2026-07-01', '2026-07-31');
    $sql = strtolower(collect(DB::getQueryLog())->pluck('query')->implode(' '));
    DB::disableQueryLog();

    // A non-sargable whereDate() would emit `date("enrolled_at")`; the sargable range must not.
    expect($sql)->not->toContain('date(')
        ->and($sql)->toContain('enrolled_at');
});

// ---------------------------------------------------------------- H1 index migration

it('creates the audited indexes and drops the superseded ones on a fresh database', function () {
    $present = fn (string $name): bool => DB::table('pg_indexes')->where('indexname', $name)->exists();

    // New indexes exist.
    foreach ([
        'enrollments_course_id_status_index',
        'enrollments_course_id_enrolled_at_index',
        'orders_paid_at_index',
        'order_items_order_id_index',
        'order_items_product_id_index',
        'product_courses_course_id_index',
        'course_trainer_user_id_index',
        'certificates_status_issued_at_index',
        'lesson_progress_status_completed_at_index',
        'courses_catalog_listing_index',
    ] as $index) {
        expect($present($index))->toBeTrue("Expected index [{$index}] to exist.");
    }

    // Superseded prefixes are gone.
    expect($present('courses_status_visibility_index'))->toBeFalse()
        ->and($present('certificates_status_index'))->toBeFalse();
});

it('rolls the index migration back cleanly and restores the original indexes', function () {
    $present = fn (string $name): bool => DB::table('pg_indexes')->where('indexname', $name)->exists();

    // Postgres has transactional DDL and RefreshDatabase wraps the test in a transaction, so calling
    // down()/up() here mutates only this test's rolled-back transaction.
    $migration = require base_path('database/migrations/2026_07_21_000050_add_performance_indexes.php');

    $migration->down();
    expect($present('enrollments_course_id_enrolled_at_index'))->toBeFalse()
        ->and($present('courses_catalog_listing_index'))->toBeFalse()
        ->and($present('courses_status_visibility_index'))->toBeTrue()   // original restored
        ->and($present('certificates_status_index'))->toBeTrue();        // original restored

    $migration->up();
    expect($present('courses_catalog_listing_index'))->toBeTrue()
        ->and($present('courses_status_visibility_index'))->toBeFalse();
});
