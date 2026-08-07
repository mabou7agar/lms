<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Media\Filament\Resources\MediaAssetResource;
use App\Platform\Media\Filament\Resources\MediaAssetResource\Pages\ListMediaAssets;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Enums\MediaStatus;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

if (! function_exists('mediaPanelUser')) {
    function mediaPanelUser(string ...$roles): User
    {
        $user = User::factory()->create();

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user;
    }
}

if (! function_exists('mediaPanelBoot')) {
    function mediaPanelBoot(User $actor): void
    {
        test()->actingAs($actor, 'web');

        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);

        Route::name('filament.admin.')->prefix('admin')->middleware('web')->group(function () use ($panel): void {
            MediaAssetResource::registerRoutes($panel);
        });
    }
}

beforeEach(function () {
    config()->set('media.ingestion.default', 'fake');
    $this->seed(RolePermissionSeeder::class);
});

it('D8: shows the Retry action only for a retryable (failed) asset', function () {
    $superAdmin = mediaPanelUser('super_admin');
    mediaPanelBoot($superAdmin);

    $failed = MediaAsset::factory()->failed()->create();
    $ready = MediaAsset::factory()->ready()->create();

    Livewire::test(ListMediaAssets::class)
        ->assertActionVisible(TestAction::make('retryIngestion')->table($failed))
        ->assertActionHidden(TestAction::make('retryIngestion')->table($ready));
});

it('D8: retrying a failed asset delegates to the ingestion service and recovers it to Ready', function () {
    $superAdmin = mediaPanelUser('super_admin');
    mediaPanelBoot($superAdmin);

    $failed = MediaAsset::factory()->failed()->create();

    Livewire::test(ListMediaAssets::class)
        ->callAction(TestAction::make('retryIngestion')->table($failed));

    // The fake provider verifies the existing remote asset as ready; the engine advances the row.
    expect($failed->refresh()->status)->toBe(MediaStatus::Ready);
});

it('D3: filters the list by uploader (created_by)', function () {
    $superAdmin = mediaPanelUser('super_admin');
    mediaPanelBoot($superAdmin);

    $mine = MediaAsset::factory()->ready()->ownedBy(101)->create();
    $theirs = MediaAsset::factory()->ready()->ownedBy(202)->create();

    Livewire::test(ListMediaAssets::class)
        ->filterTable('created_by', 101)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('D3: filters the list by created_at date range', function () {
    $superAdmin = mediaPanelUser('super_admin');
    mediaPanelBoot($superAdmin);

    $old = MediaAsset::factory()->ready()->create(['created_at' => now()->subDays(10)]);
    $recent = MediaAsset::factory()->ready()->create(['created_at' => now()]);

    Livewire::test(ListMediaAssets::class)
        ->filterTable('created_at', ['created_from' => now()->subDays(3)->toDateString()])
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$old]);
});
