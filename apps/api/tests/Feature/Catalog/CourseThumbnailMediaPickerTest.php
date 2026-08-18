<?php

use App\Domains\Catalog\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Media\Enums\MediaVisibility;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use App\Platform\Shared\Media\Contracts\MediaPickerPort;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('media.ingestion.default', 'local');
    config()->set('media.local.disk', 'media_local');
    Storage::fake('media_local');
    Storage::fake(FileUploadConfiguration::disk());
    // Variants are derived off the request path in dev/production (a queued job); keep them queued here
    // so this covers the upload path itself rather than the imaging pipeline.
    Queue::fake();
    // Nothing in an admin upload may reach the network — the local provider writes straight to disk.
    Http::preventStrayRequests();
});

/** Sign in as a super_admin on the admin panel so the Catalog resource pages resolve. */
function actAsCatalogAdmin(): User
{
    test()->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    test()->actingAs($admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

it('wires the course thumbnail as an upload-first media picker', function () {
    actAsCatalogAdmin();
    $course = Course::factory()->create();

    Livewire::test(EditCourse::class, ['record' => $course->public_id])
        ->assertFormFieldExists('thumbnail_path', fn (Field $field): bool => $field instanceof MediaPicker);
});

it('classifies and preserves a legacy thumbnail URL instead of dropping it', function () {
    $legacy = 'https://cdn.example.com/legacy/course-thumb.jpg';

    expect(MediaPicker::classifyValue($legacy))->toBe('legacy');

    actAsCatalogAdmin();
    $course = Course::factory()->create([
        'thumbnail_path' => $legacy,
        'title_i18n' => ['en' => 'Legacy Course'],
    ]);

    // The picker shows the legacy value (dual-read) and a save that never touches it keeps it intact.
    Livewire::test(EditCourse::class, ['record' => $course->public_id])
        ->assertFormSet(['thumbnail_path' => $legacy])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($course->fresh()->thumbnail_path)->toBe($legacy);
});

it('stores a picked media asset public_id as the thumbnail reference', function () {
    $admin = actAsCatalogAdmin();
    $course = Course::factory()->create(['title_i18n' => ['en' => 'Course With Thumb']]);

    $asset = MediaAsset::factory()->ready()->create([
        'type' => MediaType::Image->value,
        'purpose' => MediaPurpose::LessonImage->value,
        'created_by' => $admin->id,
        'original_filename' => 'thumb.png',
        'mime_type' => 'image/png',
    ]);

    Livewire::test(EditCourse::class, ['record' => $course->public_id])
        ->fillForm(['thumbnail_path' => (string) $asset->public_id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($course->fresh()->thumbnail_path)->toBe((string) $asset->public_id)
        ->and(MediaPicker::classifyValue($course->fresh()->thumbnail_path))->toBe('reference');
});

it('rejects a foreign media asset that the operator does not own', function () {
    actAsCatalogAdmin();
    $course = Course::factory()->create(['title_i18n' => ['en' => 'Course Foreign Pick']]);

    // Owned by someone else — the field save-rule re-authorizes ownership and must refuse it.
    $foreign = MediaAsset::factory()->ready()->create([
        'type' => MediaType::Image->value,
        'purpose' => MediaPurpose::LessonImage->value,
        'created_by' => 9_999,
    ]);

    Livewire::test(EditCourse::class, ['record' => $course->public_id])
        ->fillForm(['thumbnail_path' => (string) $foreign->public_id])
        ->call('save')
        ->assertHasFormErrors(['thumbnail_path']);

    expect($course->fresh()->thumbnail_path)->toBeNull();
});

/*
 | Upload-through-the-picker coverage. The tests above only exercised PICKING an existing asset, which
 | is why a broken UPLOAD action went unnoticed: the modal created no asset (or created one and then
 | aborted), left the field empty and reported nothing, so the operator's image never reached
 | `courses.thumbnail_path` and the card fell back to its placeholder forever.
 */

/** Drive the picker's "Upload new" modal exactly as the admin panel does. */
function uploadCourseThumbnail(Course $course, UploadedFile $file): Testable
{
    return Livewire::test(EditCourse::class, ['record' => $course->public_id])
        ->mountFormComponentAction('thumbnail_path', 'upload')
        // Assigning an UploadedFile to the mounted modal's state runs Livewire's real temporary-upload
        // flow, so the action receives a genuine TemporaryUploadedFile — not a hand-built stand-in.
        ->set('mountedActions.0.data.file', $file)
        ->callMountedFormComponentAction();
}

it('uploads a new image through the picker and persists it as the thumbnail reference', function () {
    actAsCatalogAdmin();
    $course = Course::factory()->create(['title_i18n' => ['en' => 'Course Upload Thumb']]);

    $component = uploadCourseThumbnail($course, fakePngUpload('thumb.png', 800, 450))
        ->assertHasNoErrors();

    // A ready, PUBLIC image asset exists and the field now holds its public_id (never a URL/path).
    $asset = MediaAsset::query()->sole();
    expect($asset->type)->toBe(MediaType::Image)
        ->and($asset->status)->toBe(MediaStatus::Ready)
        ->and($asset->visibility)->toBe(MediaVisibility::Public)
        ->and(MediaPicker::classifyValue((string) $asset->public_id))->toBe('reference');

    // Saving the form carries that reference into the column the catalog reads.
    $component->call('save')->assertHasNoFormErrors();

    expect($course->fresh()->thumbnail_path)->toBe((string) $asset->public_id);
});

it('accepts a thumbnail whose pixel ratio is not exactly 16:9', function () {
    actAsCatalogAdmin();
    $course = Course::factory()->create(['title_i18n' => ['en' => 'Course Off Ratio']]);

    // 806x453 is what the operator's real exports measured. Declaring a 16:9 crop must frame the image,
    // never veto it — an admin thumbnail is not rejected for being a few pixels off.
    uploadCourseThumbnail($course, fakePngUpload('off-ratio.png', 806, 453))
        ->assertHasNoErrors()
        ->call('save')
        ->assertHasNoFormErrors();

    expect(MediaPicker::classifyValue($course->fresh()->thumbnail_path))->toBe('reference');
});

it('uploads through the credential-free fake provider without touching the network', function () {
    // `fake` is the DEFAULT provider whenever MEDIA_INGESTION_PROVIDER is unset. It stores no bytes and
    // its upload URL is a sentinel host (upload.fake.test), so forwarding to it turned every admin upload
    // into a DNS failure. Http::preventStrayRequests() fails this test if that regresses.
    config()->set('media.ingestion.default', 'fake');

    actAsCatalogAdmin();
    $course = Course::factory()->create(['title_i18n' => ['en' => 'Course Fake Provider']]);

    uploadCourseThumbnail($course, fakePngUpload('thumb.png', 800, 450))
        ->assertHasNoErrors()
        ->call('save')
        ->assertHasNoFormErrors();

    expect(MediaPicker::classifyValue($course->fresh()->thumbnail_path))->toBe('reference');
});

it('surfaces a failed upload as an error notification instead of an empty field', function () {
    // The original action swallowed every failure with a bare `return`, so a rejected upload closed the
    // modal, left the field empty and told the operator nothing — the reason 35 images ended up stranded
    // in Livewire's temp directory with nobody aware anything had gone wrong.
    $port = Mockery::mock(MediaPickerPort::class)->shouldIgnoreMissing();
    $port->shouldReceive('upload')->andThrow(new RuntimeException('The provider rejected the upload.'));
    app()->instance(MediaPickerPort::class, $port);

    actAsCatalogAdmin();
    $course = Course::factory()->create(['title_i18n' => ['en' => 'Course Failing Upload']]);

    uploadCourseThumbnail($course, fakePngUpload('thumb.png', 800, 450));

    Notification::assertNotified('Upload failed');

    expect($course->fresh()->thumbnail_path)->toBeNull();
});

it('refuses an empty upload with a visible field error rather than a silent no-op', function () {
    actAsCatalogAdmin();
    $course = Course::factory()->create(['title_i18n' => ['en' => 'Course Empty Upload']]);

    // Submitting the modal with no file must not look like a success: the required rule reports it and
    // nothing is written on either side.
    Livewire::test(EditCourse::class, ['record' => $course->public_id])
        ->callFormComponentAction('thumbnail_path', 'upload', ['file' => []])
        ->assertHasErrors();

    expect(MediaAsset::query()->count())->toBe(0)
        ->and($course->fresh()->thumbnail_path)->toBeNull();
});
