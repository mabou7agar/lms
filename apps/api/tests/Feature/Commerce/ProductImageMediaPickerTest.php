<?php

use App\Contexts\Commerce\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Contexts\Commerce\Models\Product;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

if (! function_exists('commerceMediaPanelAdmin')) {
    function commerceMediaPanelAdmin(): User
    {
        test()->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        test()->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}

it('adds the products.image_path column', function () {
    expect(Schema::hasColumn('products', 'image_path'))->toBeTrue();
});

it('wires the product image as an upload-first media picker', function () {
    commerceMediaPanelAdmin();
    $product = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $product->public_id])
        ->assertFormFieldExists('image_path', fn (Field $field): bool => $field instanceof MediaPicker);
});

it('preserves a legacy product image URL through a save', function () {
    $legacy = 'https://cdn.example.com/legacy/product.jpg';

    expect(MediaPicker::classifyValue($legacy))->toBe('legacy');

    commerceMediaPanelAdmin();
    $product = Product::factory()->create([
        'image_path' => $legacy,
        'title_i18n' => ['en' => 'Legacy Product'],
    ]);
    // A product is only saveable once it says what it sells and for how much, so give it both —
    // otherwise the form fails validation before it can round-trip the image path under test.
    $product->courses()->sync([(int) Course::factory()->create()->id]);
    $product->prices()->create(['currency' => 'SAR', 'amount_minor' => 19900, 'is_default' => true]);

    Livewire::test(EditProduct::class, ['record' => $product->public_id])
        ->assertFormSet(['image_path' => $legacy])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->image_path)->toBe($legacy);
});
