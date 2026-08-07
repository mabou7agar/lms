<?php

use App\Domains\Catalog\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaType;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
