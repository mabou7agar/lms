<?php

use App\Platform\Identity\Models\User;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Policies\MediaAssetPolicy;
use App\Platform\Shared\Media\Contracts\MediaEnrollmentPort;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Bind an enrollment port with a fixed answer. */
function bindEnrollment(bool $answer): void
{
    app()->bind(MediaEnrollmentPort::class, fn () => new class($answer) implements MediaEnrollmentPort
    {
        public function __construct(private bool $answer) {}

        public function canAccessCourseMedia(int $actorId, int $courseId): bool
        {
            return $this->answer;
        }
    });
}

it('lets the owner play a ready asset', function () {
    $owner = User::factory()->create();
    $asset = MediaAsset::factory()->ready()->ownedBy($owner->id)->create();

    expect(app(MediaAssetPolicy::class)->playback($owner, $asset))->toBeTrue();
});

it('never plays an unready asset even for the owner', function () {
    $owner = User::factory()->create();
    $asset = MediaAsset::factory()->processing()->ownedBy($owner->id)->create();

    expect(app(MediaAssetPolicy::class)->playback($owner, $asset))->toBeFalse();
});

it('lets an enrolled learner play a ready course asset', function () {
    bindEnrollment(true);
    $learner = User::factory()->create();
    $asset = MediaAsset::factory()->ready()->ownedBy(999)->forCourse(100)->create();

    expect(app(MediaAssetPolicy::class)->playback($learner, $asset))->toBeTrue();
});

it('denies a non-enrolled learner', function () {
    bindEnrollment(false);
    $learner = User::factory()->create();
    $asset = MediaAsset::factory()->ready()->ownedBy(999)->forCourse(100)->create();

    expect(app(MediaAssetPolicy::class)->playback($learner, $asset))->toBeFalse();
});
