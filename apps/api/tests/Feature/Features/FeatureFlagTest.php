<?php

use App\Platform\Features\Filament\Resources\FeatureFlagResource\Pages\EditFeatureFlag;
use App\Platform\Features\Models\FeatureFlag;
use App\Platform\Features\Services\FeatureFlagService;
use App\Platform\Features\Support\Feature;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

/** Fresh evaluator each time (its per-request flag cache must not leak between assertions). */
function flags(): FeatureFlagService
{
    $service = app(FeatureFlagService::class);
    $service->flush();

    return $service;
}

it('returns TRUE for a missing flag key (default-on, never hide a feature)', function () {
    expect(flags()->isEnabled('totally_unknown_flag'))->toBeTrue();
});

it('honours the is_enabled kill-switch', function () {
    FeatureFlag::factory()->key('commerce')->create(['is_enabled' => true]);
    expect(flags()->isEnabled('commerce'))->toBeTrue();

    FeatureFlag::query()->where('key', 'commerce')->update(['is_enabled' => false]);
    expect(flags()->isEnabled('commerce'))->toBeFalse();
});

it('disables a flag scoped to a different environment and enables a matching one', function () {
    FeatureFlag::factory()->key('prod_only')->forEnvironment('production')->create();
    FeatureFlag::factory()->key('here_only')->forEnvironment(app()->environment())->create();

    expect(flags()->isEnabled('prod_only'))->toBeFalse()
        ->and(flags()->isEnabled('here_only'))->toBeTrue();
});

it('targets a flag to specific roles only (guest and other roles are excluded)', function () {
    $this->seed(RolePermissionSeeder::class);

    FeatureFlag::factory()->key('admin_only')->forRoles([Role::Admin->value])->create();

    $admin = User::factory()->create();
    $admin->assignRole(SpatieRole::findByName(Role::Admin->value, 'web'));

    $student = User::factory()->create();
    $student->assignRole(SpatieRole::findByName(Role::Student->value, 'web'));

    expect(flags()->isEnabled('admin_only', $admin))->toBeTrue()
        ->and(flags()->isEnabled('admin_only', $student))->toBeFalse()
        ->and(flags()->isEnabled('admin_only', null))->toBeFalse();
});

it('respects the starts_at / ends_at active window', function () {
    FeatureFlag::factory()->key('future')->window(now()->addDay(), null)->create();
    FeatureFlag::factory()->key('expired')->window(null, now()->subDay())->create();
    FeatureFlag::factory()->key('current')->window(now()->subDay(), now()->addDay())->create();

    expect(flags()->isEnabled('future'))->toBeFalse()
        ->and(flags()->isEnabled('expired'))->toBeFalse()
        ->and(flags()->isEnabled('current'))->toBeTrue();
});

it('treats rollout_percentage 0 as nobody and 100 as everyone', function () {
    FeatureFlag::factory()->key('rollout_none')->rollout(0)->create();
    FeatureFlag::factory()->key('rollout_all')->rollout(100)->create();

    expect(flags()->isEnabled('rollout_none'))->toBeFalse()
        ->and(flags()->isEnabled('rollout_all'))->toBeTrue();
});

it('buckets a partial rollout deterministically and stably across calls', function () {
    FeatureFlag::factory()->key('half')->rollout(50)->create();

    $expected = (crc32('half|guest') % 100) < 50;

    $first = flags()->isEnabled('half');
    $second = flags()->isEnabled('half');

    expect($first)->toBe($expected)->and($second)->toBe($expected);
});

it('resolves the full map via all() and the Feature facade', function () {
    FeatureFlag::factory()->key('on_flag')->create();
    FeatureFlag::factory()->key('off_flag')->disabled()->create();

    $map = flags()->all();

    expect($map)->toHaveKeys(['on_flag', 'off_flag'])
        ->and($map['on_flag'])->toBeTrue()
        ->and($map['off_flag'])->toBeFalse();

    app(FeatureFlagService::class)->flush();
    expect(Feature::enabled('on_flag'))->toBeTrue()
        ->and(Feature::enabled('off_flag'))->toBeFalse();
});

it('exposes the resolved boolean map over GET /api/v1/feature-flags', function () {
    FeatureFlag::factory()->key('events')->create();
    FeatureFlag::factory()->key('blog')->disabled()->create();

    $res = $this->getJson('/api/v1/feature-flags')->assertOk();

    expect($res->json('data.flags.events'))->toBeTrue()
        ->and($res->json('data.flags.blog'))->toBeFalse();
});

it('resolves the map for the authenticated user (role-targeted flags apply)', function () {
    $this->seed(RolePermissionSeeder::class);
    FeatureFlag::factory()->key('admin_panel')->forRoles([Role::Admin->value])->create();

    $admin = User::factory()->create();
    $admin->assignRole(SpatieRole::findByName(Role::Admin->value, 'web'));
    Sanctum::actingAs($admin);

    $res = $this->getJson('/api/v1/feature-flags')->assertOk();

    expect($res->json('data.flags.admin_panel'))->toBeTrue();
});

it('lets an admin toggle a flag via the Filament resource and audits the change', function () {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $flag = FeatureFlag::factory()->key('experimental')->create(['is_enabled' => true]);

    // Drive the toggle through the form's Livewire state exactly as the browser does via wire:model.
    // fillForm(['is_enabled' => false]) is a no-op on this boolean Toggle under Filament v4 (the
    // dehydrated state stays true and nothing persists); set('data.is_enabled', ...) is the state the
    // real save reads. The assertions below (row persisted false + audit written) are unchanged.
    Livewire::test(EditFeatureFlag::class, ['record' => $flag->public_id])
        ->set('data.is_enabled', false)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($flag->refresh()->is_enabled)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'feature_flag.updated')->exists())->toBeTrue();
});
