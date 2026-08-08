<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Policies\MediaAssetPolicy;
use App\Platform\Shared\Media\Contracts\MediaEnrollmentPort;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * T1 Option-N tenant dimension of MediaAssetPolicy. A GLOBAL asset (organization_id NULL) is
 * authorizable by everyone (the shared catalog); an org-owned asset is authorizable ONLY under its
 * owning tenant — never cross-tenant, even for its creator or an enrolled learner. super_admin bypasses.
 *
 * The existing MediaAssetPolicyTest runs with NULL-org users + global assets, so the tenant gate is a
 * no-op there and those tests remain green; only these tests establish a tenant context.
 */
function bindMediaEnrollment(bool $answer): void
{
    app()->bind(MediaEnrollmentPort::class, fn () => new class($answer) implements MediaEnrollmentPort
    {
        public function __construct(private bool $answer) {}

        public function canAccessCourseMedia(int $actorId, int $courseId): bool
        {
            return $this->answer;
        }
    });
}

beforeEach(function (): void {
    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

it('lets an org1 owner manage/play their own org1 asset', function (): void {
    $owner = User::factory()->create();
    $asset = MediaAsset::factory()->ready()->ownedBy($owner->id)->organization(1)->create();

    app(TenantContext::class)->set(TenantId::from(1));

    $policy = app(MediaAssetPolicy::class);
    expect($policy->view($owner, $asset))->toBeTrue()
        ->and($policy->playback($owner, $asset))->toBeTrue();
});

it('denies an org1 actor management/playback of an org2-owned asset (tenant gate), even as its creator', function (): void {
    $actor = User::factory()->create();
    // The actor is nominally the creator, but the asset is org2-private and the active tenant is org1.
    $asset = MediaAsset::factory()->ready()->ownedBy($actor->id)->organization(2)->create();

    app(TenantContext::class)->set(TenantId::from(1));

    $policy = app(MediaAssetPolicy::class);
    expect($policy->view($actor, $asset))->toBeFalse()
        ->and($policy->update($actor, $asset))->toBeFalse()
        ->and($policy->delete($actor, $asset))->toBeFalse()
        ->and($policy->playback($actor, $asset))->toBeFalse();
});

it('denies a cross-tenant enrolled learner an org2 course asset (tenant gate precedes enrollment)', function (): void {
    bindMediaEnrollment(true); // enrollment WOULD allow it — the tenant gate must still deny.
    $learner = User::factory()->create();
    $asset = MediaAsset::factory()->ready()->ownedBy(999)->forCourse(100)->organization(2)->create();

    app(TenantContext::class)->set(TenantId::from(1));

    expect(app(MediaAssetPolicy::class)->playback($learner, $asset))->toBeFalse();
});

it('still lets an enrolled learner play a GLOBAL course asset under a resolved tenant (backward compatible)', function (): void {
    bindMediaEnrollment(true);
    $learner = User::factory()->create();
    $asset = MediaAsset::factory()->ready()->ownedBy(999)->forCourse(100)->create(); // global (NULL)

    app(TenantContext::class)->set(TenantId::from(1));

    expect(app(MediaAssetPolicy::class)->playback($learner, $asset))->toBeTrue();
});

it('lets super_admin manage/play an org2 asset across tenants (policy before() bypass)', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $asset = MediaAsset::factory()->ready()->organization(2)->create();

    app(TenantContext::class)->set(TenantId::from(1));

    $policy = app(MediaAssetPolicy::class);
    expect($policy->view($superAdmin, $asset))->toBeTrue()
        ->and($policy->playback($superAdmin, $asset))->toBeTrue();
});
