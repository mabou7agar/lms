<?php

use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Exceptions\CoursePublishBlockedException;
use App\Domains\Catalog\Exceptions\CourseTransitionException;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Services\CourseLifecycle;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lifecycle(): CourseLifecycle
{
    return app(CourseLifecycle::class);
}

/** A draft course whose curriculum passes the readiness guard, so it can actually publish. */
function publishableCourse(): Course
{
    $course = Course::factory()->create([
        'status' => CourseStatus::Draft,
        'description' => 'A real description.',
        'thumbnail_path' => 'courses/thumb.jpg',
    ]);

    $section = Section::factory()->published()->create(['course_id' => $course->id]);
    Lesson::factory()->published()->create(['section_id' => $section->id, 'position' => 1]);

    return $course;
}

it('applies a legal transition and records an audit entry', function () {
    $course = Course::factory()->create();
    $actor = User::factory()->create();

    lifecycle()->transition($course, CourseStatus::Review, $actor);

    expect($course->fresh()->status)->toBe(CourseStatus::Review);

    $entry = AuditLog::query()->where('action', 'catalog.course.transitioned')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and((int) $entry->getAttribute('actor_id'))->toBe($actor->id)
        ->and($entry->getAttribute('context'))->toMatchArray(['from' => 'draft', 'to' => 'review']);
});

it('rejects an illegal transition with a CourseTransitionException', function () {
    $course = Course::factory()->create(); // draft

    // Draft -> Approved is not in the legal map (must pass through Review first).
    expect(fn () => lifecycle()->transition($course, CourseStatus::Approved))
        ->toThrow(CourseTransitionException::class);

    expect($course->fresh()->status)->toBe(CourseStatus::Draft)
        ->and(AuditLog::query()->where('action', 'catalog.course.transitioned')->count())->toBe(0);
});

it('still blocks publishing an unready course, even through the state machine', function () {
    $course = Course::factory()->create(['status' => CourseStatus::Draft]); // no sections/lessons

    expect(fn () => lifecycle()->transition($course, CourseStatus::Published))
        ->toThrow(CoursePublishBlockedException::class);

    expect($course->fresh()->status)->toBe(CourseStatus::Draft);
});

it('publishes a ready course and stamps last_published_at', function () {
    $course = publishableCourse();

    lifecycle()->transition($course, CourseStatus::Published);

    $fresh = $course->fresh();
    expect($fresh->status)->toBe(CourseStatus::Published)
        ->and($fresh->last_published_at)->not->toBeNull()
        ->and($fresh->published_at)->not->toBeNull();
});

it('restores an archived course to draft', function () {
    $course = Course::factory()->archived()->create();

    lifecycle()->transition($course, CourseStatus::Draft);

    expect($course->fresh()->status)->toBe(CourseStatus::Draft);
});

it('moves a published course to the distinct Unpublished state', function () {
    $course = Course::factory()->published()->create();

    lifecycle()->transition($course, CourseStatus::Unpublished);

    expect($course->fresh()->status)->toBe(CourseStatus::Unpublished);
});

it('requires a future time to schedule', function () {
    $course = Course::factory()->create();

    expect(fn () => lifecycle()->transition(
        $course,
        CourseStatus::Scheduled,
        null,
        new DateTimeImmutable('-1 hour'),
    ))->toThrow(CourseTransitionException::class);
});

it('records one audit entry per transition', function () {
    $course = Course::factory()->create();

    lifecycle()->transition($course, CourseStatus::Review);
    lifecycle()->transition($course->fresh(), CourseStatus::Approved);

    expect(AuditLog::query()->where('action', 'catalog.course.transitioned')->count())->toBe(2);
});

// -------------------------------------------------------------- public visibility

it('excludes every non-published lifecycle state from the public listing', function () {
    Course::factory()->published()->create(['title' => 'Live']);
    Course::factory()->create(['title' => 'A draft']);
    Course::factory()->review()->create(['title' => 'In review']);
    Course::factory()->approved()->create(['title' => 'Approved']);
    Course::factory()->scheduled()->create(['title' => 'Scheduled']);
    Course::factory()->unpublished()->create(['title' => 'Unpublished']);
    Course::factory()->archived()->create(['title' => 'Archived']);

    $res = $this->getJson('/api/v1/courses')->assertOk();

    expect($res->json('meta.total'))->toBe(1)
        ->and($res->json('data.0.title'))->toBe('Live');
});

it('returns 404 for the detail of an unpublished or scheduled course', function () {
    $unpublished = Course::factory()->unpublished()->create();
    $scheduled = Course::factory()->scheduled()->create();
    $review = Course::factory()->review()->create();
    $approved = Course::factory()->approved()->create();

    $this->getJson('/api/v1/courses/'.$unpublished->public_id)->assertNotFound();
    $this->getJson('/api/v1/courses/'.$scheduled->public_id)->assertNotFound();
    $this->getJson('/api/v1/courses/'.$review->public_id)->assertNotFound();
    $this->getJson('/api/v1/courses/'.$approved->public_id)->assertNotFound();
});
