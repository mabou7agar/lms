<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserProfile;
use App\Platform\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

it('lists public instructors with their profile and resolved media (P1)', function () {
    // A PUBLIC, ready MediaAsset — its public_id is the stored reference the picker persisted.
    $asset = MediaAsset::factory()->ready()->publicVisibility()->create([
        'storage_key' => 'media/lesson_image/secret-object-key.jpg',
    ]);
    $reference = (string) $asset->public_id;
    $legacyCover = 'legacy/covers/sara.jpg';

    $user = User::factory()->create(['name' => 'Dr. Sara']);
    $user->assignRole('instructor');
    UserProfile::factory()->instructor()->create([
        'user_id' => $user->id,
        'profile_photo' => $reference,
        'cover_photo' => $legacyCover,
        'display_order' => 1,
    ]);

    // A non-public instructor profile must NOT surface.
    $hidden = User::factory()->create(['name' => 'Hidden Coach']);
    $hidden->assignRole('instructor');
    UserProfile::factory()->instructor()->create(['user_id' => $hidden->id, 'is_public' => false]);

    // A non-instructor must NOT surface.
    $regular = User::factory()->create(['name' => 'Regular User']);
    UserProfile::factory()->create(['user_id' => $regular->id]);

    $res = $this->getJson('/api/v1/instructors')->assertOk();

    $names = collect($res->json('data'))->pluck('name');
    expect($names)->toContain('Dr. Sara')
        ->and($names)->not->toContain('Hidden Coach')
        ->and($names)->not->toContain('Regular User');

    $sara = collect($res->json('data'))->firstWhere('name', 'Dr. Sara');

    expect($sara['headline_i18n'])->toMatchArray(['en' => 'Senior Instructor', 'ar' => 'مدرب أول'])
        ->and($sara['bio_i18n']['ar'])->toBe('مربٍّ متمرس.')
        ->and($sara['specialties'])->toContain('Leadership')
        // P1: a PUBLIC reference resolves to a stable public URL (never the raw public_id / storage key).
        ->and($sara['profile_photo'])->toContain('/media/public/')
        ->and($sara['profile_photo'])->toContain($reference)
        ->and($sara['profile_photo'])->not->toContain((string) $asset->storage_key)
        // A legacy path passes through unchanged.
        ->and($sara['cover_photo'])->toBe($legacyCover);
});
