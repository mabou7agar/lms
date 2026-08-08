<?php

use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Attach a publishable (readiness-passing) curriculum to a course. */
function attachReadyCurriculum(Course $course): void
{
    $section = Section::factory()->published()->create(['course_id' => $course->id]);
    Lesson::factory()->published()->create(['section_id' => $section->id, 'position' => 1]);
}

it('publishes a scheduled course whose time has arrived and that passes readiness', function () {
    $course = Course::factory()->scheduled(now()->subMinute())->create([
        'description' => 'Ready', 'thumbnail_path' => 'thumb.jpg',
    ]);
    attachReadyCurriculum($course);

    $this->artisan('courses:publish-scheduled')->assertSuccessful();

    $fresh = $course->fresh();
    expect($fresh->status)->toBe(CourseStatus::Published)
        ->and($fresh->scheduled_publish_at)->toBeNull()
        ->and($fresh->last_published_at)->not->toBeNull();
});

it('leaves a future-dated scheduled course untouched', function () {
    $course = Course::factory()->scheduled(now()->addHour())->create([
        'description' => 'Ready', 'thumbnail_path' => 'thumb.jpg',
    ]);
    attachReadyCurriculum($course);

    $this->artisan('courses:publish-scheduled')->assertSuccessful();

    expect($course->fresh()->status)->toBe(CourseStatus::Scheduled);
});

it('keeps a due-but-unready scheduled course scheduled rather than corrupting it', function () {
    // Due, but no sections/lessons — the readiness guard refuses to publish it.
    $course = Course::factory()->scheduled(now()->subMinute())->create();

    $this->artisan('courses:publish-scheduled')->assertSuccessful();

    $fresh = $course->fresh();
    expect($fresh->status)->toBe(CourseStatus::Scheduled)
        ->and($fresh->scheduled_publish_at)->not->toBeNull();
});
