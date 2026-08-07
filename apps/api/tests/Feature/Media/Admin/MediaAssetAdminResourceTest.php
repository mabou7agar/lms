<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Media\Exceptions\MediaInUseException;
use App\Platform\Media\Filament\Resources\MediaAssetResource;
use App\Platform\Media\Filament\Resources\MediaAssetResource\Pages\ListMediaAssets;
use App\Platform\Media\Filament\Resources\MediaAssetResource\Pages\ViewMediaAsset;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaAttachment;
use App\Platform\Media\Services\MediaDeletionService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

if (! function_exists('mediaPanelUser')) {
    /** Create a user under the web guard with the given roles. */
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
    /**
     * Sign the actor in on the admin panel and register the (deliberately unregistered) Media
     * Filament routes for the duration of the test so the resource pages resolve. Production
     * registration is the integration owner's job in AdminPanelProvider.
     */
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
    $this->seed(RolePermissionSeeder::class);
});

it('allows the DAM list only for admin/super_admin operators', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $superAdmin = mediaPanelUser('super_admin');
    $admin = mediaPanelUser('admin');
    $instructor = mediaPanelUser('instructor');
    $student = mediaPanelUser('student');

    $this->actingAs($superAdmin, 'web');
    expect(MediaAssetResource::canViewAny())->toBeTrue();

    $this->actingAs($admin, 'web');
    expect(MediaAssetResource::canViewAny())->toBeTrue();

    $this->actingAs($instructor, 'web');
    expect(MediaAssetResource::canViewAny())->toBeFalse();

    $this->actingAs($student, 'web');
    expect(MediaAssetResource::canViewAny())->toBeFalse();
});

it('never authors or edits an asset through the panel', function () {
    $asset = MediaAsset::factory()->ready()->create();

    expect(MediaAssetResource::canCreate())->toBeFalse()
        ->and(MediaAssetResource::canEdit($asset))->toBeFalse();
});

it('delegates per-record view to the Media policy: super_admin sees any, a plain admin only its own', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $superAdmin = mediaPanelUser('super_admin');
    $admin = mediaPanelUser('admin');

    $foreign = MediaAsset::factory()->ready()->ownedBy(9_999)->create();
    $ownedByAdmin = MediaAsset::factory()->ready()->ownedBy($admin->id)->create();

    // super_admin is the global manager (policy before() bypass): every asset is viewable.
    $this->actingAs($superAdmin, 'web');
    expect(MediaAssetResource::canView($foreign))->toBeTrue()
        ->and(MediaAssetResource::canView($ownedByAdmin))->toBeTrue();

    // A plain admin reaches the list, but per-record view is the Media policy: owner yes, others no
    // (cross-user denial — an asset owned by someone else, with no course to manage, stays private).
    $this->actingAs($admin, 'web');
    expect(MediaAssetResource::canView($ownedByAdmin))->toBeTrue()
        ->and(MediaAssetResource::canView($foreign))->toBeFalse()
        ->and(MediaAssetResource::canDelete($foreign))->toBeFalse();

    // A student is denied outright.
    $student = mediaPanelUser('student');
    $this->actingAs($student, 'web');
    expect(MediaAssetResource::canView($ownedByAdmin))->toBeFalse();
});

it('produces a signed preview URL that leaks neither the storage key nor the provider ref', function () {
    $asset = MediaAsset::factory()->ready()->create([
        'storage_key' => 'RAWSTORAGEKEY0123456789',
        'provider_ref' => 'RAWPROVIDERREF9876543210',
    ]);

    $url = MediaAssetResource::signedPreviewUrl($asset);

    expect($url)->not->toBeNull()
        ->and($url)->not->toContain((string) $asset->storage_key)
        ->and($url)->not->toContain((string) $asset->provider_ref)
        ->and($url)->not->toContain('RAWSTORAGEKEY')
        ->and($url)->not->toContain('RAWPROVIDERREF');
});

it('issues no signed URL for an asset that is not yet ready', function () {
    $asset = MediaAsset::factory()->processing()->create();

    expect(MediaAssetResource::signedPreviewUrl($asset))->toBeNull();
});

it('redacts a provider reference to a last-4 fingerprint and never the raw value', function () {
    $redacted = MediaAssetResource::redactProviderRef('mux_secret_reference_7890');

    expect($redacted)->toBe('•••• 7890')
        ->and($redacted)->not->toContain('secret')
        ->and(MediaAssetResource::redactProviderRef(null))->toBe('—');
});

it('renders the read-only detail without exposing raw provider identifiers', function () {
    $superAdmin = mediaPanelUser('super_admin');
    mediaPanelBoot($superAdmin);

    $asset = MediaAsset::factory()->ready()->create([
        'original_filename' => 'orientation-video.mp4',
        'storage_key' => 'RAWSTORAGEKEYABCDEF',
        'provider_ref' => 'RAWPROVIDERREFGHIJK',
    ]);

    Livewire::test(ViewMediaAsset::class, ['record' => $asset->public_id])
        ->assertOk()
        ->assertSee('orientation-video.mp4')
        ->assertDontSee('RAWSTORAGEKEYABCDEF')
        ->assertDontSee('RAWPROVIDERREFGHIJK');
});

it('blocks a safe delete of an in-use asset through the resource action, delegating to the service', function () {
    $superAdmin = mediaPanelUser('super_admin');
    mediaPanelBoot($superAdmin);

    $asset = MediaAsset::factory()->ready()->create();
    MediaAttachment::factory()->create(['media_asset_id' => $asset->id]);

    Livewire::test(ListMediaAssets::class)
        ->callAction(TestAction::make('safeDelete')->table($asset));

    // The service's row-locked usage re-check refuses the delete; the asset survives untouched.
    expect(MediaAsset::query()->whereKey($asset->id)->exists())->toBeTrue()
        ->and(MediaAttachment::query()->where('media_asset_id', $asset->id)->count())->toBe(1);
});

it('allows a safe delete of an unused asset through the resource action', function () {
    $superAdmin = mediaPanelUser('super_admin');
    mediaPanelBoot($superAdmin);

    $asset = MediaAsset::factory()->ready()->create();

    Livewire::test(ListMediaAssets::class)
        ->callAction(TestAction::make('safeDelete')->table($asset));

    // Delegated to MediaDeletionService, which soft-deletes the row.
    expect(MediaAsset::query()->whereKey($asset->id)->exists())->toBeFalse()
        ->and(MediaAsset::withTrashed()->whereKey($asset->id)->exists())->toBeTrue();
});

it('confirms the underlying service guard the resource relies on: in-use is refused, unused is deleted', function () {
    $inUse = MediaAsset::factory()->ready()->create();
    MediaAttachment::factory()->create(['media_asset_id' => $inUse->id]);

    expect(fn () => app(MediaDeletionService::class)->deleteAsset($inUse, 1, false))
        ->toThrow(MediaInUseException::class);
    expect(MediaAsset::query()->whereKey($inUse->id)->exists())->toBeTrue();

    $unused = MediaAsset::factory()->ready()->create();
    app(MediaDeletionService::class)->deleteAsset($unused, 1, false);
    expect(MediaAsset::query()->whereKey($unused->id)->exists())->toBeFalse();

    // Force delete detaches every usage and then removes the asset.
    $forced = MediaAsset::factory()->ready()->create();
    MediaAttachment::factory()->create(['media_asset_id' => $forced->id]);
    app(MediaDeletionService::class)->deleteAsset($forced, 1, true);
    expect(MediaAsset::query()->whereKey($forced->id)->exists())->toBeFalse()
        ->and(MediaAttachment::query()->where('media_asset_id', $forced->id)->count())->toBe(0);
});

it('lists media with a query count that does not scale with the number of rows', function () {
    $superAdmin = mediaPanelUser('super_admin');
    mediaPanelBoot($superAdmin);

    $seed = function (): void {
        $asset = MediaAsset::factory()->ready()->create();
        MediaAttachment::factory()->create(['media_asset_id' => $asset->id]);
    };

    foreach (range(1, 3) as $ignored) {
        $seed();
    }

    // Warm first-request initialization so both measurements compare like for like.
    Livewire::test(ListMediaAssets::class);

    DB::enableQueryLog();
    Livewire::test(ListMediaAssets::class);
    $threeRows = count(DB::getQueryLog());

    foreach (range(1, 3) as $ignored) {
        $seed();
    }

    DB::flushQueryLog();
    Livewire::test(ListMediaAssets::class);
    $sixRows = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($sixRows)->toBe($threeRows);
});
