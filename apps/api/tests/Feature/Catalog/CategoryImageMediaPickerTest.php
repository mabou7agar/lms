<?php

use App\Domains\Catalog\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Domains\Catalog\Models\Category;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

if (! function_exists('catalogMediaPanelAdmin')) {
    function catalogMediaPanelAdmin(): User
    {
        test()->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        test()->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}

it('adds the categories.image_path column', function () {
    expect(Schema::hasColumn('categories', 'image_path'))->toBeTrue();
});

it('wires the category image as an upload-first media picker', function () {
    catalogMediaPanelAdmin();
    $category = Category::factory()->create();

    Livewire::test(EditCategory::class, ['record' => $category->public_id])
        ->assertFormFieldExists('image_path', fn (Field $field): bool => $field instanceof MediaPicker);
});

it('preserves a legacy category image URL through a save', function () {
    $legacy = 'https://cdn.example.com/legacy/category.png';

    expect(MediaPicker::classifyValue($legacy))->toBe('legacy');

    catalogMediaPanelAdmin();
    $category = Category::factory()->create([
        'image_path' => $legacy,
        'name_i18n' => ['en' => 'Legacy Category'],
    ]);

    Livewire::test(EditCategory::class, ['record' => $category->public_id])
        ->assertFormSet(['image_path' => $legacy])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($category->fresh()->image_path)->toBe($legacy);
});
