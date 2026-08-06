<?php

use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Exceptions\CannotImpersonateException;
use App\Platform\Identity\Exceptions\NotImpersonatingException;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Services\ImpersonationManager;
use App\Platform\Shared\Audit\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seeds the four system roles and every identity permission, incl. identity.users.impersonate.
    $this->seed(IdentitySeeder::class);
});

/** A user holding exactly the impersonate permission (via a dedicated custom role). */
function imp_support(): User
{
    $role = SpatieRole::findOrCreate('support_impersonator_test', 'web');
    $role->givePermissionTo('identity.users.impersonate');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function imp_manager(): ImpersonationManager
{
    return app(ImpersonationManager::class);
}

it('lets an authorised support user impersonate an ordinary user and audits the start', function () {
    $support = imp_support();
    $target = User::factory()->create();
    $this->actingAs($support);

    imp_manager()->start($target);

    expect((int) Auth::id())->toBe((int) $target->getKey())
        ->and(imp_manager()->isImpersonating())->toBeTrue()
        ->and(imp_manager()->impersonatorId())->toBe((int) $support->getKey())
        ->and(AuditLog::where('action', 'identity.user.impersonation.started')
            ->where('subject_id', $target->getKey())->exists())->toBeTrue();
});

it('restores the original user and audits the end when leaving', function () {
    $support = imp_support();
    $target = User::factory()->create();
    $this->actingAs($support);
    imp_manager()->start($target);

    imp_manager()->leave();

    expect((int) Auth::id())->toBe((int) $support->getKey())
        ->and(imp_manager()->isImpersonating())->toBeFalse()
        ->and(AuditLog::where('action', 'identity.user.impersonation.ended')->exists())->toBeTrue();
});

it('never impersonates a super_admin, even for a super_admin actor', function () {
    $root = User::factory()->create();
    $root->assignRole(SpatieRole::findByName('super_admin', 'web'));
    $otherRoot = User::factory()->create();
    $otherRoot->assignRole(SpatieRole::findByName('super_admin', 'web'));
    $this->actingAs($root);

    expect(fn () => imp_manager()->start($otherRoot))->toThrow(CannotImpersonateException::class);
});

it('refuses self-impersonation', function () {
    $support = imp_support();
    $this->actingAs($support);

    expect(fn () => imp_manager()->start($support))->toThrow(CannotImpersonateException::class);
});

it('refuses nested impersonation', function () {
    $support = imp_support();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $this->actingAs($support);
    imp_manager()->start($first);

    expect(fn () => imp_manager()->start($second))->toThrow(CannotImpersonateException::class);
});

it('forbids a user without the impersonate permission', function () {
    $plain = User::factory()->create();
    $plain->assignRole(SpatieRole::findByName('student', 'web'));
    $target = User::factory()->create();
    $this->actingAs($plain);

    expect(fn () => imp_manager()->start($target))->toThrow(CannotImpersonateException::class);
});

it('throws when leaving without an active impersonation session', function () {
    $support = imp_support();
    $this->actingAs($support);

    expect(fn () => imp_manager()->leave())->toThrow(NotImpersonatingException::class);
});

it('exposes the impersonate ability only per permission and never for self or a super_admin', function () {
    $support = imp_support();
    $target = User::factory()->create();
    $root = User::factory()->create();
    $root->assignRole(SpatieRole::findByName('super_admin', 'web'));
    $plain = User::factory()->create();
    $plain->assignRole(SpatieRole::findByName('student', 'web'));

    expect(Gate::forUser($support)->allows('impersonate', $target))->toBeTrue()
        ->and(Gate::forUser($support)->allows('impersonate', $root))->toBeFalse()
        ->and(Gate::forUser($support)->allows('impersonate', $support))->toBeFalse()
        ->and(Gate::forUser($plain)->allows('impersonate', $target))->toBeFalse();
});
