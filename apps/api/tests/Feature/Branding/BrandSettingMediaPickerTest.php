<?php

use App\Platform\Branding\Filament\Resources\BrandSettingResource\Pages\EditBrandSetting;
use App\Platform\Branding\Models\BrandSetting;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

if (! function_exists('brandingMediaPanelAdmin')) {
    function brandingMediaPanelAdmin(): User
    {
        test()->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        test()->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}

it('wires the branding logo and certificate image fields as media pickers', function () {
    brandingMediaPanelAdmin();
    $setting = BrandSetting::current();

    Livewire::test(EditBrandSetting::class, ['record' => $setting->public_id])
        ->assertFormFieldExists('logos.logo_light', fn (Field $field): bool => $field instanceof MediaPicker)
        ->assertFormFieldExists('logos.certificate_logo', fn (Field $field): bool => $field instanceof MediaPicker)
        ->assertFormFieldExists('certificate.signature', fn (Field $field): bool => $field instanceof MediaPicker)
        ->assertFormFieldExists('certificate.stamp', fn (Field $field): bool => $field instanceof MediaPicker);
});

it('preserves a legacy branding logo path through a save (dual-read)', function () {
    $legacy = '/storage/branding/logo-light.svg';

    expect(MediaPicker::classifyValue($legacy))->toBe('legacy');

    brandingMediaPanelAdmin();
    $setting = BrandSetting::create(['logos' => ['logo_light' => $legacy]]);

    Livewire::test(EditBrandSetting::class, ['record' => $setting->public_id])
        ->assertFormSet(['logos.logo_light' => $legacy])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($setting->fresh()->logos['logo_light'])->toBe($legacy);
});
