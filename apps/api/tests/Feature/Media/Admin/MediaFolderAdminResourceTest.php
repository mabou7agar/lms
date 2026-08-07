<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Media\Filament\Resources\MediaFolderResource;
use App\Platform\Media\Filament\Resources\MediaFolderResource\Pages\ListMediaFolders;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaFolder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

if (! function_exists('folderPanelUser')) {
    function folderPanelUser(string ...$roles): User
    {
        $user = User::factory()->create();

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user;
    }
}

if (! function_exists('folderPanelBoot')) {
    function folderPanelBoot(User $actor): void
    {
        test()->actingAs($actor, 'web');

        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);

        Route::name('filament.admin.')->prefix('admin')->middleware('web')->group(function () use ($panel): void {
            MediaFolderResource::registerRoutes($panel);
        });
    }
}

beforeEach(function () {
    config()->set('media.ingestion.default', 'fake');
    $this->seed(RolePermissionSeeder::class);
});

it('D1: exposes folder management only to admin/super_admin operators', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $admin = folderPanelUser('admin');
    $student = folderPanelUser('student');

    $this->actingAs($admin, 'web');
    expect(MediaFolderResource::canViewAny())->toBeTrue();

    $this->actingAs($student, 'web');
    expect(MediaFolderResource::canViewAny())->toBeFalse();
});

it('D1: deleting a folder through the panel keeps its assets (reassigned to root)', function () {
    $superAdmin = folderPanelUser('super_admin');
    folderPanelBoot($superAdmin);

    $folder = MediaFolder::factory()->ownedBy($superAdmin->id)->create();
    $asset = MediaAsset::factory()->ready()->ownedBy($superAdmin->id)->inFolder($folder->id)->create();

    Livewire::test(ListMediaFolders::class)
        ->callAction(TestAction::make('deleteFolder')->table($folder));

    expect(MediaFolder::query()->whereKey($folder->id)->exists())->toBeFalse()
        ->and(MediaAsset::query()->whereKey($asset->id)->exists())->toBeTrue()
        ->and($asset->refresh()->folder_id)->toBeNull();
});
