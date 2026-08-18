<?php

use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserProfile;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | Both public surfaces fall back gracefully when an image is missing (CourseCover for a course,
 | initials for a trainer), so a missing upload looks like a design choice rather than a gap. This
 | report is what turns "which images are actually missing?" into something the system answers, so it
 | has to agree with what a visitor sees — including the profile_photo -> avatar_path fallback the
 | trainer cards read through.
 */

function makeTrainer(string $name, array $profile = []): User
{
    $user = User::factory()->create(['name' => $name, 'is_active' => true]);
    $user->assignRole('instructor');
    UserProfile::factory()->create(array_merge(['user_id' => $user->id, 'is_public' => true], $profile));

    return $user;
}

function imageReference(int $ownerId): string
{
    return (string) MediaAsset::factory()->ready()->create([
        'type' => MediaType::Image->value,
        'purpose' => MediaPurpose::LessonImage->value,
        'created_by' => $ownerId,
    ])->public_id;
}

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

it('lists exactly the courses and trainers that still need an upload', function () {
    $withThumb = Course::factory()->create([
        'title_i18n' => ['en' => 'Course With Thumb'],
        'thumbnail_path' => imageReference(1),
    ]);
    $withoutThumb = Course::factory()->create([
        'title_i18n' => ['en' => 'Course Needing Thumb'],
        'thumbnail_path' => null,
    ]);

    $photographed = makeTrainer('Photographed Trainer', ['profile_photo' => imageReference(1)]);
    $bare = makeTrainer('Bare Trainer', ['profile_photo' => null, 'avatar_path' => null]);

    // expectsOutputToContain() consumes output lines IN ORDER, so each expectation below must target a
    // distinct line, listed in the order the command emits them — two substrings from the same line
    // would leave the second with nothing to match.
    $this->artisan('catalog:report-missing-public-media')
        // The admin path is the whole point of the report — an operator must not have to hunt for it.
        ->expectsOutputToContain('Admin → Catalog → Courses → Edit course → Thumbnail → Upload new → Save')
        // Title AND slug on one line, so the entry is unambiguous and copy-pasteable.
        ->expectsOutputToContain('Course Needing Thumb  [slug: '.$withoutThumb->slug.']')
        ->expectsOutputToContain('Admin → Identity → Instructor Profiles → Edit trainer → Profile photo → Upload new → Save')
        ->expectsOutputToContain('Bare Trainer  [id: '.$bare->public_id.']')
        ->expectsOutputToContain('2 public image(s) still missing')
        ->assertSuccessful();

    // Anything already uploaded must NOT be nagged about.
    expect($withThumb->fresh()->thumbnail_path)->not->toBeNull()
        ->and($photographed->profile->profile_photo)->not->toBeNull();
});

it('counts a trainer as covered when only the legacy avatar_path is set', function () {
    // The public trainer card reads profile_photo OR avatar_path, so a legacy-only avatar is not a gap.
    makeTrainer('Legacy Avatar Trainer', [
        'profile_photo' => null,
        'avatar_path' => 'https://cdn.example.com/legacy/avatar.jpg',
    ]);

    $this->artisan('catalog:report-missing-public-media')
        ->expectsOutputToContain('Every active trainer has a profile photo.')
        ->assertSuccessful();
});

it('reports a clean bill of health when nothing is missing', function () {
    Course::factory()->create(['thumbnail_path' => imageReference(1)]);
    makeTrainer('Covered Trainer', ['profile_photo' => imageReference(1)]);

    $this->artisan('catalog:report-missing-public-media')
        ->expectsOutputToContain('All public course thumbnails and trainer photos are in place.')
        ->assertSuccessful();
});

it('can fail a release check while still succeeding by default', function () {
    Course::factory()->create(['title_i18n' => ['en' => 'Gap'], 'thumbnail_path' => null]);

    // Default: informational, so it never breaks an unrelated pipeline step.
    $this->artisan('catalog:report-missing-public-media')->assertSuccessful();

    // Opt-in gate for a launch checklist.
    $this->artisan('catalog:report-missing-public-media --fail-on-missing')->assertFailed();
});

it('ignores an inactive trainer, who is not on the public list at all', function () {
    $inactive = User::factory()->create(['name' => 'Retired Trainer', 'is_active' => false]);
    $inactive->assignRole('instructor');
    UserProfile::factory()->create(['user_id' => $inactive->id, 'profile_photo' => null, 'is_public' => true]);

    $this->artisan('catalog:report-missing-public-media')
        ->doesntExpectOutputToContain('Retired Trainer')
        ->assertSuccessful();
});

it('treats a course whose thumbnail is a legacy URL as already covered', function () {
    Course::factory()->create([
        'title_i18n' => ['en' => 'Legacy Thumb Course'],
        'thumbnail_path' => 'https://cdn.example.com/legacy/thumb-'.Str::random(6).'.jpg',
    ]);

    $this->artisan('catalog:report-missing-public-media')
        ->expectsOutputToContain('Every course has a thumbnail.')
        ->assertSuccessful();
});
