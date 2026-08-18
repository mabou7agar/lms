<?php

use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserProfile;
use App\Platform\Media\Jobs\GenerateImageVariantsJob;
use App\Platform\Shared\Media\Contracts\MediaPickerPort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 | The last mile of the media fix: a public_id written by the admin MediaPicker must come back out of the
 | PUBLIC catalog API as a real, fetchable URL. Without this, an upload can succeed and persist correctly
 | and the homepage/catalog/trainer cards STILL render their fallbacks, because the API hands the frontend
 | a null. Both endpoints are asserted against an asset created through the same picker seam the admin
 | panel uses, so the reference is produced exactly the way a real upload produces it.
 */

beforeEach(function () {
    config()->set('media.ingestion.default', 'local');
    config()->set('media.local.disk', 'media_local');
    Storage::fake('media_local');
    Bus::fake(GenerateImageVariantsJob::class);
    Http::preventStrayRequests();
});

/** Upload an image through the picker seam and return its stored reference (a MediaAsset public_id). */
function uploadedImageReference(int $actorId, string $filename): string
{
    return app(MediaPickerPort::class)->upload(
        actorId: $actorId,
        purpose: 'lesson_image',
        filename: $filename,
        mimeType: 'image/png',
        sizeBytes: 24,
        contents: 'png-bytes-for-'.$filename,
    );
}

it('resolves a saved thumbnail reference into a public URL on /api/v1/courses', function () {
    $admin = User::factory()->create();
    $reference = uploadedImageReference($admin->id, 'course-thumb.png');

    $course = Course::factory()->published()->create([
        'title_i18n' => ['en' => 'Course With Uploaded Thumb'],
        'thumbnail_path' => $reference,
    ]);

    $response = $this->getJson('/api/v1/courses?per_page=12')->assertOk();

    $listed = collect($response->json('data'))->firstWhere('id', (string) $course->public_id);

    expect($listed)->not->toBeNull()
        // NOT null, and not the raw reference either — a stable public URL keyed by the public id.
        ->and($listed['thumbnail_path'])->toBeString()->toContain('/media/public/'.$reference);

    // And that URL actually serves the stored bytes, so the card renders a real image.
    $this->get('/media/public/'.$reference)->assertOk();
});

it('resolves an uploaded profile photo into avatar_path on /api/v1/trainers', function () {
    $this->seed(RolePermissionSeeder::class);

    $trainer = User::factory()->create(['is_active' => true]);
    $trainer->assignRole('instructor');

    $reference = uploadedImageReference($trainer->id, 'trainer-avatar.png');

    // The admin picker writes the avatar to `profile_photo`; the public trainer ref reads it from there.
    UserProfile::factory()->create([
        'user_id' => $trainer->id,
        'profile_photo' => $reference,
        'avatar_path' => null,
        'is_public' => true,
    ]);

    $response = $this->getJson('/api/v1/trainers')->assertOk();

    $listed = collect($response->json('data'))->firstWhere('id', (string) $trainer->public_id);

    expect($listed)->not->toBeNull()
        ->and($listed['avatar_path'])->toBeString()->toContain('/media/public/'.$reference);
});

it('leaves thumbnail_path null when no image was ever uploaded, so the card fallback still applies', function () {
    $course = Course::factory()->published()->create([
        'title_i18n' => ['en' => 'Course Without Thumb'],
        'thumbnail_path' => null,
    ]);

    $response = $this->getJson('/api/v1/courses?per_page=12')->assertOk();

    $listed = collect($response->json('data'))->firstWhere('id', (string) $course->public_id);

    expect($listed)->not->toBeNull()
        ->and($listed['thumbnail_path'])->toBeNull();
});
