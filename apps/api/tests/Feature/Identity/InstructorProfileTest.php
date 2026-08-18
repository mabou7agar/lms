<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Filament\Resources\InstructorProfileResource\Pages\EditInstructorProfile;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserProfile;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaType;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

/** Sign in as a super_admin on the admin panel so the Identity resource pages resolve. */
function actAsIdentityAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    test()->actingAs($admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

function makeInstructorProfile(array $attributes = []): UserProfile
{
    $user = User::factory()->create();
    $user->assignRole('instructor');

    return UserProfile::factory()->create(array_merge(['user_id' => $user->id], $attributes));
}

it('persists a bilingual instructor profile and syncs the legacy bio scalar', function () {
    $profile = makeInstructorProfile();

    $profile->update([
        'headline_i18n' => ['en' => 'Lead Coach', 'ar' => 'المدرب الرئيسي'],
        'bio_i18n' => ['en' => 'Ten years of practice.', 'ar' => 'عشر سنوات من الخبرة.'],
        'specialties' => ['Agile', 'Coaching'],
        'social_links' => ['linkedin' => 'https://example.com/in/x'],
        'website' => 'https://example.com',
        'display_order' => 5,
        'is_public' => true,
    ]);

    $fresh = $profile->fresh();

    expect($fresh->headline_i18n)->toBe(['en' => 'Lead Coach', 'ar' => 'المدرب الرئيسي'])
        ->and($fresh->bio_i18n['ar'])->toBe('عشر سنوات من الخبرة.')
        ->and($fresh->localized('headline'))->toBe('Lead Coach')
        // HasTranslations keeps the legacy scalar `bio` synced from bio_i18n[en].
        ->and($fresh->bio)->toBe('Ten years of practice.')
        ->and($fresh->specialties)->toBe(['Agile', 'Coaching'])
        ->and($fresh->social_links)->toBe(['linkedin' => 'https://example.com/in/x'])
        ->and($fresh->display_order)->toBe(5)
        ->and($fresh->is_public)->toBeTrue();
});

it('wires the profile & cover photos as upload-first media pickers', function () {
    actAsIdentityAdmin();
    $profile = makeInstructorProfile();

    Livewire::test(EditInstructorProfile::class, ['record' => $profile->public_id])
        ->assertFormFieldExists('profile_photo', fn (Field $field): bool => $field instanceof MediaPicker)
        ->assertFormFieldExists('cover_photo', fn (Field $field): bool => $field instanceof MediaPicker);
});

it('stores photo & cover MediaAsset references through the picker and preserves a legacy value', function () {
    $admin = actAsIdentityAdmin();

    $legacyCover = 'https://cdn.example.com/legacy/cover.jpg';
    expect(MediaPicker::classifyValue($legacyCover))->toBe('legacy');

    $profile = makeInstructorProfile(['cover_photo' => $legacyCover]);

    $asset = MediaAsset::factory()->ready()->create([
        'type' => MediaType::Image->value,
        'purpose' => MediaPurpose::LessonImage->value,
        'created_by' => $admin->id,
        'original_filename' => 'avatar.png',
        'mime_type' => 'image/png',
    ]);

    Livewire::test(EditInstructorProfile::class, ['record' => $profile->public_id])
        // Dual-read: the legacy cover URL is shown, not dropped.
        ->assertFormSet(['cover_photo' => $legacyCover])
        ->fillForm([
            'profile_photo' => (string) $asset->public_id,
            'bio_i18n' => ['en' => 'Bio EN', 'ar' => 'السيرة'],
            'headline_i18n' => ['en' => 'Head EN', 'ar' => 'العنوان'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $profile->fresh();

    expect($fresh->profile_photo)->toBe((string) $asset->public_id)
        ->and(MediaPicker::classifyValue($fresh->profile_photo))->toBe('reference')
        // The untouched legacy cover survives the save intact.
        ->and($fresh->cover_photo)->toBe($legacyCover)
        ->and($fresh->bio_i18n)->toBe(['en' => 'Bio EN', 'ar' => 'السيرة'])
        ->and($fresh->headline_i18n['ar'])->toBe('العنوان');
});

it('rejects a foreign media asset the operator does not own', function () {
    actAsIdentityAdmin();
    $profile = makeInstructorProfile();

    $foreign = MediaAsset::factory()->ready()->create([
        'type' => MediaType::Image->value,
        'purpose' => MediaPurpose::LessonImage->value,
        'created_by' => 9_999,
    ]);

    Livewire::test(EditInstructorProfile::class, ['record' => $profile->public_id])
        ->fillForm(['profile_photo' => (string) $foreign->public_id])
        ->call('save')
        ->assertHasFormErrors(['profile_photo']);

    expect($profile->fresh()->profile_photo)->toBeNull();
});

/*
 | Upload-through-the-picker coverage for the avatar. As with the course thumbnail, the tests above only
 | exercised PICKING an existing asset, so a broken "Upload new" action left `profile_photo` empty and
 | every trainer card fell back to its initials placeholder.
 */
it('uploads a new profile photo through the picker and persists it as the avatar reference', function () {
    config()->set('media.ingestion.default', 'local');
    config()->set('media.local.disk', 'media_local');
    Storage::fake('media_local');
    Storage::fake(FileUploadConfiguration::disk());
    // Variant derivation is a queued job in dev/production; keep it queued so this covers the upload path.
    Queue::fake();
    // An admin upload must never reach the network (the local provider writes straight to disk).
    Http::preventStrayRequests();

    actAsIdentityAdmin();
    $profile = makeInstructorProfile();

    $component = Livewire::test(EditInstructorProfile::class, ['record' => $profile->public_id])
        ->mountFormComponentAction('profile_photo', 'upload')
        // A real Livewire temporary upload, so the action receives a genuine TemporaryUploadedFile.
        ->set('mountedActions.0.data.file', fakePngUpload('avatar.png', 400, 400))
        ->callMountedFormComponentAction()
        ->assertHasNoErrors();

    $asset = MediaAsset::query()->sole();

    $component->call('save')->assertHasNoFormErrors();

    expect($profile->fresh()->profile_photo)->toBe((string) $asset->public_id)
        ->and(MediaPicker::classifyValue($profile->fresh()->profile_photo))->toBe('reference');
});
