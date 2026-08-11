<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\SsoDomainMapping;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function ssoDomainOrgAdmin(int $organizationId): User
{
    $admin = User::factory()->create(['organization_id' => $organizationId]);
    $admin->assignRole(SpatieRole::findByName('admin', 'web'));

    return $admin;
}

it('lets an org-admin add a domain mapping', function () {
    Sanctum::actingAs(ssoDomainOrgAdmin(1));

    $res = $this->postJson('/api/v1/sso/domains', ['domain' => 'Acme.com', 'mode' => 'auto_join'])
        ->assertStatus(201);

    expect($res->json('data.domain'))->toBe('acme.com')
        ->and($res->json('data.mode'))->toBe('auto_join')
        ->and($res->json('data.verified'))->toBeFalse();

    $this->assertDatabaseHas('sso_domain_mappings', ['domain' => 'acme.com', 'organization_id' => 1]);
});

it('enforces global domain uniqueness across organizations', function () {
    Sanctum::actingAs(ssoDomainOrgAdmin(1));
    $this->postJson('/api/v1/sso/domains', ['domain' => 'acme.com', 'mode' => 'restrict'])->assertStatus(201);

    Sanctum::actingAs(ssoDomainOrgAdmin(2));
    $this->postJson('/api/v1/sso/domains', ['domain' => 'acme.com', 'mode' => 'auto_join'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SSO_DOMAIN_TAKEN');

    expect(SsoDomainMapping::where('domain', 'acme.com')->count())->toBe(1);
});

it('scopes the list to the caller organization (tenant isolation)', function () {
    $a = ssoDomainOrgAdmin(1);
    $b = ssoDomainOrgAdmin(2);

    Sanctum::actingAs($a);
    $this->postJson('/api/v1/sso/domains', ['domain' => 'org-a.com', 'mode' => 'auto_join'])->assertStatus(201);

    Sanctum::actingAs($b);
    $this->postJson('/api/v1/sso/domains', ['domain' => 'org-b.com', 'mode' => 'auto_join'])->assertStatus(201);

    Sanctum::actingAs($a);
    $res = $this->getJson('/api/v1/sso/domains')->assertOk();

    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.domain'))->toBe('org-a.com');
});

it('forbids an org-admin from modifying another organization mapping', function () {
    Sanctum::actingAs(ssoDomainOrgAdmin(2));
    $othersId = $this->postJson('/api/v1/sso/domains', ['domain' => 'org-b.com', 'mode' => 'auto_join'])
        ->json('data.id');

    Sanctum::actingAs(ssoDomainOrgAdmin(1));
    $this->patchJson("/api/v1/sso/domains/{$othersId}", ['mode' => 'restrict'])->assertStatus(403);
    $this->deleteJson("/api/v1/sso/domains/{$othersId}")->assertStatus(403);

    $this->assertDatabaseHas('sso_domain_mappings', ['domain' => 'org-b.com', 'mode' => 'auto_join']);
});

it('updates and deletes an own-org mapping', function () {
    Sanctum::actingAs(ssoDomainOrgAdmin(1));
    $id = $this->postJson('/api/v1/sso/domains', ['domain' => 'acme.com', 'mode' => 'auto_join'])->json('data.id');

    $this->patchJson("/api/v1/sso/domains/{$id}", ['mode' => 'restrict'])
        ->assertOk()
        ->assertJsonPath('data.mode', 'restrict');

    $this->deleteJson("/api/v1/sso/domains/{$id}")->assertOk();
    $this->assertDatabaseMissing('sso_domain_mappings', ['domain' => 'acme.com']);
});

it('denies a non-admin user', function () {
    $student = User::factory()->create(['organization_id' => 1]);
    $student->assignRole(SpatieRole::findByName('student', 'web'));
    Sanctum::actingAs($student);

    $this->getJson('/api/v1/sso/domains')->assertStatus(403);
    $this->postJson('/api/v1/sso/domains', ['domain' => 'acme.com', 'mode' => 'auto_join'])->assertStatus(403);
});

it('lets only a super_admin toggle verification', function () {
    $admin = ssoDomainOrgAdmin(1);
    Sanctum::actingAs($admin);
    $id = $this->postJson('/api/v1/sso/domains', ['domain' => 'acme.com', 'mode' => 'auto_join'])->json('data.id');

    // Org-admin cannot verify.
    $this->postJson("/api/v1/sso/domains/{$id}/verify", ['verified' => true])->assertStatus(403);

    $root = User::factory()->create();
    $root->assignRole(SpatieRole::findByName('super_admin', 'web'));
    Sanctum::actingAs($root);

    $this->postJson("/api/v1/sso/domains/{$id}/verify", ['verified' => true])
        ->assertOk()
        ->assertJsonPath('data.verified', true);
});

it('rejects an invalid mode and a malformed domain', function () {
    Sanctum::actingAs(ssoDomainOrgAdmin(1));

    $this->postJson('/api/v1/sso/domains', ['domain' => 'acme.com', 'mode' => 'nope'])->assertStatus(422);
    $this->postJson('/api/v1/sso/domains', ['domain' => 'not a domain', 'mode' => 'auto_join'])->assertStatus(422);
});
