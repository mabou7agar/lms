<?php

use App\Platform\Homepage\Enums\BlockType;
use App\Platform\Homepage\Filament\Resources\HomepageSectionResource\Pages\EditHomepageSection;
use App\Platform\Homepage\Models\HomepageSection;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

if (! function_exists('homepageMediaPanelAdmin')) {
    function homepageMediaPanelAdmin(): User
    {
        test()->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        test()->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}

it('wires the homepage hero image field as an upload-first media picker', function () {
    homepageMediaPanelAdmin();
    $hero = HomepageSection::factory()->block('hero')->create();

    Livewire::test(EditHomepageSection::class, ['record' => $hero->public_id])
        ->assertFormFieldExists('content.image', fn (Field $field): bool => $field instanceof MediaPicker);
});

it('converts the video poster image but keeps the external video URL as a plain input', function () {
    homepageMediaPanelAdmin();
    $video = HomepageSection::factory()->ofType(BlockType::Video)->create(['key' => 'video_test', 'position' => 30]);

    Livewire::test(EditHomepageSection::class, ['record' => $video->public_id])
        // Poster is an image -> upload-first.
        ->assertFormFieldExists('content.poster', fn (Field $field): bool => $field instanceof MediaPicker)
        // Video playback URL is an external provider reference -> NEVER converted to an upload picker.
        ->assertFormFieldExists('content.url', fn (Field $field): bool => ! $field instanceof MediaPicker);
});
