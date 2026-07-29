<?php

use App\Contexts\Learning\Actions\Enrollment\GrantEnrollmentAction;
use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Learning\Contracts\CourseNavigationPort;
use App\Platform\Shared\Learning\Contracts\LessonAvailabilityPort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/RuntimeSupport.php';

beforeEach(fn () => bootLearningRuntime());

it('renders the runtime curriculum in order with completion and lock state', function () {
    [$course, , $lessons] = publishedCourseWithLessons(3);
    $a = $lessons->get(0);
    $b = $lessons->get(1);
    $b->prerequisites()->attach($a->id);

    $user = User::factory()->create();
    app(GrantEnrollmentAction::class)->executeByUserId($user->id, $course->id, EnrollmentSource::Free);
    Sanctum::actingAs($user);

    $res = $this->getJson("/api/v1/courses/{$course->public_id}/curriculum")->assertOk();
    $rows = collect($res->json('data.sections.0.lessons'));

    // Ordering preserved.
    expect($rows->pluck('id')->all())->toBe($lessons->pluck('public_id')->all());

    $byId = $rows->keyBy('id');
    expect($byId[$a->public_id]['locked'])->toBeFalse()
        ->and($byId[$b->public_id]['locked'])->toBeTrue()
        ->and($byId[$b->public_id]['lock_reason'])->toBe('prerequisite_incomplete');
});

it('drip-locks a lesson whose release is in the future using server time', function () {
    [$course, , $lessons] = publishedCourseWithLessons(2);
    $gated = $lessons->get(1);

    $fake = new FakeLessonAvailabilityPort;
    $fake->releaseAt[$gated->id] = now()->addDays(3);
    app()->instance(LessonAvailabilityPort::class, $fake);

    $user = User::factory()->create();
    app(GrantEnrollmentAction::class)->executeByUserId($user->id, $course->id, EnrollmentSource::Free);
    Sanctum::actingAs($user);

    $byId = collect($this->getJson("/api/v1/courses/{$course->public_id}/curriculum")->assertOk()->json('data.sections.0.lessons'))->keyBy('id');

    expect($byId[$gated->public_id]['locked'])->toBeTrue()
        ->and($byId[$gated->public_id]['lock_reason'])->toBe('drip_not_released')
        ->and($byId[$gated->public_id]['available_at'])->not->toBeNull();

    // The drip lock also blocks runtime progress, not just the curriculum flag.
    $this->postJson("/api/v1/lessons/{$gated->public_id}/viewed")
        ->assertStatus(403)->assertJsonPath('error.code', 'LEARNING_LESSON_LOCKED');
});

it('unlocks prerequisite-gated lessons when free navigation is enabled for the course', function () {
    [$course, , $lessons] = publishedCourseWithLessons(2);
    $gated = $lessons->get(1);
    $gated->prerequisites()->attach($lessons->get(0)->id);

    $nav = new FakeCourseNavigationPort;
    $nav->free[$course->id] = true;
    app()->instance(CourseNavigationPort::class, $nav);

    $user = User::factory()->create();
    app(GrantEnrollmentAction::class)->executeByUserId($user->id, $course->id, EnrollmentSource::Free);
    Sanctum::actingAs($user);

    $byId = collect($this->getJson("/api/v1/courses/{$course->public_id}/curriculum")->assertOk()->json('data.sections.0.lessons'))->keyBy('id');
    expect($byId[$gated->public_id]['locked'])->toBeFalse();

    // Free navigation also lets the learner progress the otherwise-gated lesson.
    $this->postJson("/api/v1/lessons/{$gated->public_id}/viewed")->assertOk();
});
