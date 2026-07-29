<?php

use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Publishing\Data\ChangeSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
});

/**
 * The change summary reports unavailable in every case today, because no published snapshot is
 * persisted. These tests pin that it says so HONESTLY — the failure mode to guard against is a
 * future change that starts comparing a course to itself and reports "no changes", which reads as
 * a reassurance rather than an absence of information.
 */
function summaryUser(string $role = 'instructor'): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName($role, 'web'));

    return $user;
}

function summaryCourse(User $owner, CourseStatus $status = CourseStatus::Published): Course
{
    $course = Course::factory()->create(['status' => $status, 'published_at' => now()]);
    $course->syncTrainers([$owner->id]);

    return $course;
}

it('reports no baseline for a published course', function () {
    $me = summaryUser();
    $course = summaryCourse($me);

    $this->actingAs($me, 'sanctum')
        ->getJson("/api/v1/teach/courses/{$course->public_id}/changes")
        ->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.reason', 'No published baseline available.');
});

it('reports no baseline for a draft that has never been published', function () {
    $me = summaryUser();
    $course = Course::factory()->create(['status' => CourseStatus::Draft, 'published_at' => null]);
    $course->syncTrainers([$me->id]);

    $this->actingAs($me, 'sanctum')
        ->getJson("/api/v1/teach/courses/{$course->public_id}/changes")
        ->assertOk()
        ->assertJsonPath('data.available', false);
});

it('never claims there are no changes', function () {
    $me = summaryUser();
    $course = summaryCourse($me);

    $body = $this->actingAs($me, 'sanctum')
        ->getJson("/api/v1/teach/courses/{$course->public_id}/changes")
        ->assertOk()
        ->getContent();

    // "no changes" and "we cannot tell" are different statements. Only the second is true.
    expect($body)->not->toContain('"changes"')
        ->and($body)->not->toContain('sections_added');
});

it('hides the summary for a course the caller does not train', function () {
    $me = summaryUser();
    $theirs = summaryCourse(summaryUser());

    $this->actingAs($me, 'sanctum')
        ->getJson("/api/v1/teach/courses/{$theirs->public_id}/changes")
        ->assertNotFound();
});

it('refuses a learner', function () {
    $course = summaryCourse(summaryUser());

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/teach/courses/{$course->public_id}/changes")
        ->assertForbidden();
});

it('fixes the shape a real baseline will use', function () {
    // The producer changes when snapshots land; the contract does not.
    $summary = ChangeSummary::fromBaseline(['sections_added' => 2], '2026-01-01T00:00:00+00:00');

    expect($summary->toArray())->toHaveKeys(['available', 'baseline_published_at', 'changes'])
        ->and($summary->available)->toBeTrue();

    expect(ChangeSummary::CATEGORIES)->toContain(
        'metadata_changed', 'sections_added', 'lessons_removed',
        'lesson_content_changed', 'assessment_reference_changed',
        'pricing_changed', 'access_settings_changed',
    );
});
