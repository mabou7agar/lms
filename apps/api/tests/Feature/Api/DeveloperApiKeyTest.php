<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Enums\Permission;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/** An org-admin (holds ManageUsers + ViewUsers via the admin role) attached to an organization. */
function orgAdmin(int $organizationId): User
{
    $admin = User::factory()->create(['organization_id' => $organizationId]);
    $admin->assignRole(SpatieRole::findByName('admin', 'web'));

    return $admin;
}

it('creates a key and returns the plaintext token exactly once, never again', function () {
    $admin = orgAdmin(1);
    Sanctum::actingAs($admin, ['*']);

    $create = $this->postJson('/api/v1/api-keys', [
        'name' => 'CI integration',
        'scopes' => ['account:read', 'org:read'],
    ])->assertCreated();

    $plaintext = $create->json('data.token');
    expect($plaintext)->toBeString()->not->toBe('');
    expect($create->json('data.scopes'))->toEqual(['account:read', 'org:read']);

    // The plaintext must never appear again in the listing, nor may the hash be exposed.
    $list = $this->getJson('/api/v1/api-keys')->assertOk();
    $body = $list->getContent();

    expect($body)->not->toContain($plaintext);
    expect($list->json('data.0'))->not->toHaveKey('token');
    expect($list->json('data.0'))->toHaveKeys(['id', 'name', 'scopes', 'last_used_at', 'expires_at', 'created_at']);

    // The stored row keeps only a hash — never the plaintext.
    $stored = PersonalAccessToken::query()->first();
    expect($stored->token)->not->toBe($plaintext);
});

it('honours an optional expiry on create', function () {
    $admin = orgAdmin(1);
    Sanctum::actingAs($admin, ['*']);

    $this->postJson('/api/v1/api-keys', [
        'name' => 'expiring',
        'scopes' => ['account:read'],
        'expires_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    expect(PersonalAccessToken::query()->first()->expires_at)->not->toBeNull();
});

it('rejects a scope outside the catalog', function () {
    $admin = orgAdmin(1);
    Sanctum::actingAs($admin, ['*']);

    $this->postJson('/api/v1/api-keys', [
        'name' => 'bad',
        'scopes' => ['courses:read'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('cannot grant a scope beyond the creator\'s own permissions', function () {
    // Can manage keys, but lacks ViewUsers — so org:read (which requires it) is not grantable.
    $limited = User::factory()->create(['organization_id' => 1]);
    $limited->givePermissionTo(Permission::ManageUsers->value);
    Sanctum::actingAs($limited, ['*']);

    $this->postJson('/api/v1/api-keys', [
        'name' => 'over-reach',
        'scopes' => ['org:read'],
    ])->assertStatus(403)->assertJsonPath('error.code', 'SCOPE_FORBIDDEN');

    // A scope requiring no permission is still grantable by the same caller.
    $this->postJson('/api/v1/api-keys', [
        'name' => 'fine',
        'scopes' => ['account:read'],
    ])->assertCreated();
});

it('forbids managing keys without the manage-users permission', function () {
    $plain = User::factory()->create(['organization_id' => 1]);
    Sanctum::actingAs($plain, ['*']);

    $this->getJson('/api/v1/api-keys')->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
});

it('lists and revokes only the caller organization\'s keys (tenant isolation)', function () {
    $adminA = orgAdmin(1);
    Sanctum::actingAs($adminA, ['*']);
    $keyAId = $this->postJson('/api/v1/api-keys', ['name' => 'A key', 'scopes' => ['account:read']])
        ->assertCreated()->json('data.id');

    $adminB = orgAdmin(2);
    Sanctum::actingAs($adminB, ['*']);
    $this->postJson('/api/v1/api-keys', ['name' => 'B key', 'scopes' => ['account:read']])->assertCreated();

    // Org B sees only its own key, never org A's.
    $listB = $this->getJson('/api/v1/api-keys')->assertOk();
    expect($listB->json('data'))->toHaveCount(1);
    expect($listB->json('data.0.name'))->toBe('B key');

    // Org B cannot revoke org A's key: it is invisible, so revocation is a 404.
    $this->deleteJson("/api/v1/api-keys/{$keyAId}")->assertStatus(404);
    expect(PersonalAccessToken::whereKey($keyAId)->exists())->toBeTrue();

    // Org A can revoke its own key.
    Sanctum::actingAs($adminA, ['*']);
    $this->deleteJson("/api/v1/api-keys/{$keyAId}")->assertOk();
    expect(PersonalAccessToken::whereKey($keyAId)->exists())->toBeFalse();
});
