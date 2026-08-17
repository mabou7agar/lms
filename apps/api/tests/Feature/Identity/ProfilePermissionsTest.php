<?php

declare(strict_types=1);

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Enums\Permission;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * The profile discloses the caller's own permissions so the UI can stop advertising destinations
 * that will only refuse them — a company owner was being shown Brand & Domains and SSO, both of
 * which authorize against `identity.users.manage`.
 *
 * Two properties matter and are pinned here. It is scoped to SELF: one user's permission set is not
 * another user's business, and it is the shape of an attack surface if it leaks. And it is a HINT:
 * hiding a link removes no guard, so these tests also check the endpoint still refuses.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('lists the permissions the caller holds', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Sanctum::actingAs($admin);

    $permissions = $this->getJson('/api/v1/profile')->assertOk()->json('data.permissions');

    expect($permissions)->toBeArray()
        ->and($permissions)->toContain(Permission::ManageUsers->value);
});

it('reports everything for a super_admin, whose authority is implicit', function (): void {
    $root = User::factory()->create();
    $root->assignRole('super_admin');

    Sanctum::actingAs($root);

    // super_admin carries no permission rows — policies grant it through before() hooks — so the
    // naive answer is "holds nothing", which would hide from it every screen it exists to run.
    $permissions = $this->getJson('/api/v1/profile')->assertOk()->json('data.permissions');

    expect($permissions)->toContain(Permission::ManageUsers->value)
        ->and(count($permissions))->toBeGreaterThan(1);
});

it('reports an empty set for someone who holds nothing, not a missing field', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/v1/profile')->assertOk();

    // Empty and absent mean different things to the client: empty is "you hold nothing", absent is
    // "this deployment does not tell you", and the second must keep every nav entry visible.
    expect($response->json('data'))->toHaveKey('permissions')
        ->and($response->json('data.permissions'))->toBe([]);
});

it('does not disclose one user’s permissions to another', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $other = User::factory()->create();

    Sanctum::actingAs($other);

    // Whatever endpoint returns someone else's user payload must not carry their permissions.
    $response = $this->getJson('/api/v1/profile')->assertOk();

    expect($response->json('data.permissions'))->toBe([])
        ->and($response->json('data.id'))->toBe($other->public_id);
});

it('still refuses the endpoint the hint is about', function (): void {
    $owner = User::factory()->create();

    Sanctum::actingAs($owner);

    // The nav no longer offers this, but the guard is what actually stops them.
    $this->getJson('/api/v1/org/branding')->assertForbidden();
});
