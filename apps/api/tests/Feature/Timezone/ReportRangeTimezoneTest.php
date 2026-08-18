<?php

use App\Contexts\Analytics\Models\ReportDefinition;
use App\Contexts\Analytics\Services\ReportingEngine;
use App\Contexts\Learning\Analytics\EnrollmentStatsAdapter;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Models\Course;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Reporting day-boundary timezone handling. The optional `timezone` param defaults to 'UTC', which
 * must be byte-for-byte identical to the pre-timezone behaviour; an explicit IANA zone shifts the
 * calendar-day boundary. Assertions use fixed UTC instants and explicit zones — never machine tz.
 */

// An enrollment at 22:00 UTC on 2024-06-15 falls on 2024-06-16 in Riyadh (+03): the same instant
// belongs to a different calendar day depending on the zone the day boundary is drawn in.
function seedBoundaryEnrollment(): Course
{
    $course = Course::factory()->create();

    Enrollment::factory()->create([
        'course_id' => $course->id,
        'enrolled_at' => CarbonImmutable::parse('2024-06-15 22:00:00', 'UTC'),
    ]);

    return $course;
}

it('EnrollmentStatsAdapter default matches the explicit UTC behaviour', function () {
    $course = seedBoundaryEnrollment();
    $adapter = app(EnrollmentStatsAdapter::class);

    $default = $adapter->statsForCourses([$course->id], '2024-06-15', '2024-06-15');
    $utc = $adapter->statsForCourses([$course->id], '2024-06-15', '2024-06-15', 'UTC');

    // Both draw the boundary in UTC: [2024-06-15 00:00, 2024-06-16 00:00), so 22:00 UTC is counted.
    expect($default->enrollments)->toBe(1)
        ->and($utc->enrollments)->toBe(1);
});

it('EnrollmentStatsAdapter shifts the day boundary for an explicit zone', function () {
    $course = seedBoundaryEnrollment();
    $adapter = app(EnrollmentStatsAdapter::class);

    // In Riyadh the 2024-06-15 window is [2024-06-14 21:00, 2024-06-15 21:00) UTC, so the 22:00 UTC
    // enrollment (2024-06-16 local) falls OUTSIDE the requested day.
    $riyadh = $adapter->statsForCourses([$course->id], '2024-06-15', '2024-06-15', 'Asia/Riyadh');

    expect($riyadh->enrollments)->toBe(0);

    // Requesting the following day in Riyadh does capture it.
    $riyadhNext = $adapter->statsForCourses([$course->id], '2024-06-16', '2024-06-16', 'Asia/Riyadh');

    expect($riyadhNext->enrollments)->toBe(1);
});

it('ReportingEngine range with no timezone equals the explicit UTC range', function () {
    $report = ReportDefinition::factory()->create(['metric_keys' => ['enrollments']]);
    $engine = app(ReportingEngine::class);

    $default = $engine->run($report, ['from' => '2024-06-15', 'to' => '2024-06-15']);
    $utc = $engine->run($report, ['from' => '2024-06-15', 'to' => '2024-06-15', 'timezone' => 'UTC']);

    expect($default['from'])->toBe('2024-06-15')
        ->and($default['to'])->toBe('2024-06-15')
        ->and($utc['from'])->toBe($default['from'])
        ->and($utc['to'])->toBe($default['to']);
});

it('ReportingEngine range shifts the from boundary for an explicit zone', function () {
    $report = ReportDefinition::factory()->create(['metric_keys' => ['enrollments']]);
    $engine = app(ReportingEngine::class);

    // 2024-06-15 startOfDay in Riyadh is 2024-06-14 21:00 UTC, so the from date resolves to 2024-06-14.
    $riyadh = $engine->run($report, ['from' => '2024-06-15', 'to' => '2024-06-15', 'timezone' => 'Asia/Riyadh']);

    expect($riyadh['from'])->toBe('2024-06-14')
        ->and($riyadh['to'])->toBe('2024-06-15');
});
