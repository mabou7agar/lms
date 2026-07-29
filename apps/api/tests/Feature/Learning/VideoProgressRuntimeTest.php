<?php

use App\Contexts\Learning\Actions\Enrollment\GrantEnrollmentAction;
use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Domains\Authoring\Enums\LessonType;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Media\Contracts\MediaAssetPort;
use App\Platform\Shared\Media\Data\MediaAccessPolicy;
use App\Platform\Shared\Media\Data\MediaAssetRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/RuntimeSupport.php';

beforeEach(fn () => bootLearningRuntime());

/** Bind a server-authoritative duration for one lesson, so completion cannot be forced by a client. */
function fakeVideoDuration(int $lessonId, int $duration): void
{
    $asset = new MediaAssetRef('pub_'.$lessonId, 'mux', 'pb_'.$lessonId, null, 'video/mp4', $duration, new MediaAccessPolicy);

    app()->instance(MediaAssetPort::class, new class($lessonId, $asset) implements MediaAssetPort
    {
        public function __construct(private int $lessonId, private MediaAssetRef $asset) {}

        public function assetForLesson(int $lessonId): ?MediaAssetRef
        {
            return $lessonId === $this->lessonId ? $this->asset : null;
        }
    });
}

function enrolledVideoLearner(): array
{
    [$course, , $lessons] = publishedCourseWithLessons(1);
    $lesson = $lessons->first();
    $lesson->forceFill(['type' => LessonType::Video->value])->save();

    $user = User::factory()->create();
    app(GrantEnrollmentAction::class)->executeByUserId($user->id, $course->id, EnrollmentSource::Free);
    Sanctum::actingAs($user);

    return [$course, $lesson, $user];
}

it('stores the resume position and does not complete below threshold', function () {
    [, $lesson] = enrolledVideoLearner();
    fakeVideoDuration($lesson->id, 100);

    $res = $this->postJson("/api/v1/lessons/{$lesson->public_id}/video-progress", ['position_seconds' => 40])->assertOk();

    expect($res->json('data.position_seconds'))->toBe(40)
        ->and($res->json('data.completed'))->toBeFalse();
});

it('rejects an impossible position past the media duration', function () {
    [, $lesson] = enrolledVideoLearner();
    fakeVideoDuration($lesson->id, 100);

    $this->postJson("/api/v1/lessons/{$lesson->public_id}/video-progress", ['position_seconds' => 200])
        ->assertStatus(422)->assertJsonPath('error.code', 'LEARNING_INVALID_PROGRESS');
});

it('rejects a negative position at validation', function () {
    [, $lesson] = enrolledVideoLearner();
    fakeVideoDuration($lesson->id, 100);

    $this->postJson("/api/v1/lessons/{$lesson->public_id}/video-progress", ['position_seconds' => -5])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('lets the server decide completion at threshold and never trusts a client complete flag', function () {
    [$course, $lesson] = enrolledVideoLearner();
    fakeVideoDuration($lesson->id, 100);

    // A client claiming "complete" at 10s must NOT complete: watched is below threshold.
    $early = $this->postJson("/api/v1/lessons/{$lesson->public_id}/video-progress", ['position_seconds' => 10, 'completed' => true])->assertOk();
    expect($early->json('data.completed'))->toBeFalse();

    // Crossing 95% of 100s => server marks complete, and the lesson auto-completes.
    $done = $this->postJson("/api/v1/lessons/{$lesson->public_id}/video-progress", ['position_seconds' => 97])->assertOk();
    expect($done->json('data.completed'))->toBeTrue();

    $byId = collect($this->getJson("/api/v1/courses/{$course->public_id}/curriculum")->assertOk()->json('data.sections.0.lessons'))->keyBy('id');
    expect($byId[$lesson->public_id]['completed'])->toBeTrue();
});

it('does not regress watched progress on an out-of-order rewind beat', function () {
    [, $lesson] = enrolledVideoLearner();
    fakeVideoDuration($lesson->id, 100);

    $this->postJson("/api/v1/lessons/{$lesson->public_id}/video-progress", ['position_seconds' => 80])->assertOk();
    $rewound = $this->postJson("/api/v1/lessons/{$lesson->public_id}/video-progress", ['position_seconds' => 20])->assertOk();

    // watched_seconds stays at the furthest point (80), regardless of the later rewind.
    expect($rewound->json('data.watched_seconds'))->toBe(80);
});

it('refuses video progress for a learner without access', function () {
    [, $lesson] = enrolledVideoLearner();
    fakeVideoDuration($lesson->id, 100);
    Sanctum::actingAs(User::factory()->create()); // different, unenrolled user

    $this->postJson("/api/v1/lessons/{$lesson->public_id}/video-progress", ['position_seconds' => 10])
        ->assertStatus(403);
});
