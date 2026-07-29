<?php

use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonProgress;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Sprint 7 — H2. The learner player resolved lesson access with 2–3 queries PER lesson
 * (canAccessByUserId in a loop). It now resolves the whole curriculum in one prerequisite query
 * using the enrollment and completed ids already in hand. These pin BOTH halves of the acceptance
 * criteria: the query count no longer scales with lesson count, AND who-can-see-what is unchanged.
 */
function playerCourseWithLessons(User $user, int $lessons): Course
{
    $course = Course::factory()->published()->create();
    $section = Section::factory()->published()->create(['course_id' => $course->id, 'position' => 0]);

    for ($i = 0; $i < $lessons; $i++) {
        Lesson::factory()->published()->create(['section_id' => $section->id, 'position' => $i]);
    }

    Enrollment::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);

    return $course;
}

it('loads the player with a bounded query count that does not scale with lesson count', function () {
    $user = User::factory()->create();
    $small = playerCourseWithLessons($user, 5);
    $large = playerCourseWithLessons($user, 30);
    Sanctum::actingAs($user);

    // Warm up any first-request initialization so the two measurements compare like for like.
    $this->getJson("/api/v1/courses/{$small->public_id}/learn")->assertOk();

    DB::enableQueryLog();
    $this->getJson("/api/v1/courses/{$small->public_id}/learn")->assertOk();
    $smallCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    $this->getJson("/api/v1/courses/{$large->public_id}/learn")->assertOk();
    $largeCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Constant query count for 5 vs 30 lessons — the per-lesson N+1 is gone. The old loop would
    // have issued ~5 extra queries per lesson, so 30 lessons would dwarf 5.
    expect($largeCount)->toBe($smallCount)
        ->and($largeCount)->toBeLessThan(15);
});

it('preserves lesson access rules exactly: preview open, prerequisite gates the next lesson', function () {
    $user = User::factory()->create();
    $course = Course::factory()->published()->create();
    $section = Section::factory()->published()->create(['course_id' => $course->id, 'position' => 0]);

    $open = Lesson::factory()->published()->create(['section_id' => $section->id, 'position' => 0]);
    $gated = Lesson::factory()->published()->create(['section_id' => $section->id, 'position' => 1]);
    $preview = Lesson::factory()->published()->preview()->create(['section_id' => $section->id, 'position' => 2]);
    $gated->prerequisites()->attach($open->id);

    $enrollment = Enrollment::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);
    Sanctum::actingAs($user);

    $lessons = fn () => collect($this->getJson("/api/v1/courses/{$course->public_id}/learn")->assertOk()->json('data.sections'))
        ->flatMap(fn (array $s): array => $s['lessons'])
        ->keyBy('id');

    $before = $lessons();
    // Open lesson (no prereq) unlocked; preview always unlocked; gated lesson locked until its
    // prerequisite is completed.
    expect($before[$open->public_id]['locked'])->toBeFalse()
        ->and($before[$preview->public_id]['locked'])->toBeFalse()
        ->and($before[$gated->public_id]['locked'])->toBeTrue();

    // Completing the prerequisite unlocks the gated lesson — same rule as the per-lesson service.
    LessonProgress::factory()->completed()->create(['enrollment_id' => $enrollment->id, 'lesson_id' => $open->id]);

    expect($lessons()[$gated->public_id]['locked'])->toBeFalse();
});

it('still refuses the player to a learner without an active enrollment', function () {
    $user = User::factory()->create();
    $course = Course::factory()->published()->create();
    Sanctum::actingAs($user);

    // Access resolution changed; enrollment gating did not.
    $this->getJson("/api/v1/courses/{$course->public_id}/learn")->assertStatus(403);
});
