<?php

use App\Platform\Branding\Models\CustomDomain;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function brandingOrgAdmin(int $organizationId): User
{
    $admin = User::factory()->create(['organization_id' => $organizationId]);
    $admin->assignRole(SpatieRole::findByName('admin', 'web'));

    return $admin;
}

function brandingSuperAdmin(): User
{
    $root = User::factory()->create();
    $root->assignRole(SpatieRole::findByName('super_admin', 'web'));

    return $root;
}

it('returns the GLOBAL payload unchanged when the host is unknown (no override)', function () {
    $res = $this->getJson('/api/v1/branding')->assertOk();

    expect($res->json('data.identity.brand_name.en'))->toBe('HElbaron')
        ->and($res->json('data.identity.brand_name.ar'))->toBe('إلبارون')
        ->and($res->json('data.theme.colors.primary'))->toBe('oklch(0.36 0.045 185)')
        ->and($res->json('data.theme.colors.secondary'))->toBe('oklch(0.91 0.03 86)')
        ->and($res->json('data.certificate.qr_position'))->toBe('bottom-right');
});

it('resolves a verified custom domain to the org merged brand and inherits the rest from global', function () {
    $admin = brandingOrgAdmin(1);

    Sanctum::actingAs($admin);
    $this->putJson('/api/v1/org/branding', [
        'brand_name_en' => 'Acme',
        'primary_color' => '#ff0000',
    ])->assertOk();

    // Add the domain (org-admin) then verify it (super_admin-only).
    $id = $this->postJson('/api/v1/org/domains', ['host' => 'brand.acme.test'])->assertStatus(201)->json('data.id');
    Sanctum::actingAs(brandingSuperAdmin());
    $this->postJson("/api/v1/org/domains/{$id}/verify", ['verified' => true])->assertOk();

    $res = $this->getJson('http://brand.acme.test/api/v1/branding')->assertOk();

    // Overridden fields win...
    expect($res->json('data.identity.brand_name.en'))->toBe('Acme')
        ->and($res->json('data.theme.colors.primary'))->toBe('#ff0000')
        // ...untouched fields still inherit the global brand.
        ->and($res->json('data.identity.brand_name.ar'))->toBe('إلبارون')
        ->and($res->json('data.theme.colors.secondary'))->toBe('oklch(0.91 0.03 86)');
});

it('falls back to the GLOBAL brand for an UNVERIFIED custom domain', function () {
    $admin = brandingOrgAdmin(1);

    Sanctum::actingAs($admin);
    $this->putJson('/api/v1/org/branding', ['brand_name_en' => 'Acme'])->assertOk();
    // Added but never verified.
    $this->postJson('/api/v1/org/domains', ['host' => 'unverified.acme.test'])->assertStatus(201);

    $res = $this->getJson('http://unverified.acme.test/api/v1/branding')->assertOk();

    expect($res->json('data.identity.brand_name.en'))->toBe('HElbaron');
});

it('enforces GLOBAL host uniqueness across organizations (duplicate => 422)', function () {
    Sanctum::actingAs(brandingOrgAdmin(1));
    $this->postJson('/api/v1/org/domains', ['host' => 'shared.acme.test'])->assertStatus(201);

    Sanctum::actingAs(brandingOrgAdmin(2));
    $this->postJson('/api/v1/org/domains', ['host' => 'shared.acme.test'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    expect(CustomDomain::where('host', 'shared.acme.test')->count())->toBe(1);
});

it('scopes the domain list to the caller organization (tenant isolation)', function () {
    Sanctum::actingAs(brandingOrgAdmin(1));
    $this->postJson('/api/v1/org/domains', ['host' => 'org-a.test'])->assertStatus(201);

    Sanctum::actingAs(brandingOrgAdmin(2));
    $this->postJson('/api/v1/org/domains', ['host' => 'org-b.test'])->assertStatus(201);

    Sanctum::actingAs(brandingOrgAdmin(1));
    $res = $this->getJson('/api/v1/org/domains')->assertOk();

    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.host'))->toBe('org-a.test');
});

it('forbids an org-admin from deleting another organization domain', function () {
    Sanctum::actingAs(brandingOrgAdmin(2));
    $othersId = $this->postJson('/api/v1/org/domains', ['host' => 'org-b.test'])->json('data.id');

    Sanctum::actingAs(brandingOrgAdmin(1));
    $this->deleteJson("/api/v1/org/domains/{$othersId}")->assertStatus(403);

    $this->assertDatabaseHas('custom_domains', ['host' => 'org-b.test']);
});

it('lets only a super_admin verify a custom domain', function () {
    $admin = brandingOrgAdmin(1);
    Sanctum::actingAs($admin);
    $id = $this->postJson('/api/v1/org/domains', ['host' => 'verify.acme.test'])->json('data.id');

    // Org-admin cannot verify.
    $this->postJson("/api/v1/org/domains/{$id}/verify", ['verified' => true])->assertStatus(403);

    Sanctum::actingAs(brandingSuperAdmin());
    $this->postJson("/api/v1/org/domains/{$id}/verify", ['verified' => true])
        ->assertOk()
        ->assertJsonPath('data.verified', true);
});

it('keeps org brands isolated: org A only ever reads its OWN brand', function () {
    Sanctum::actingAs(brandingOrgAdmin(1));
    $this->putJson('/api/v1/org/branding', ['brand_name_en' => 'Alpha'])->assertOk();

    Sanctum::actingAs(brandingOrgAdmin(2));
    $this->putJson('/api/v1/org/branding', ['brand_name_en' => 'Beta'])->assertOk();

    Sanctum::actingAs(brandingOrgAdmin(1));
    $res = $this->getJson('/api/v1/org/branding')->assertOk();

    expect($res->json('data.identity.brand_name.en'))->toBe('Alpha');
});

it('validates and sanitises brand inputs (invalid hex and script are rejected)', function () {
    Sanctum::actingAs(brandingOrgAdmin(1));

    // Invalid hex colour is rejected.
    $this->putJson('/api/v1/org/branding', ['primary_color' => 'red'])->assertStatus(422);
    $this->putJson('/api/v1/org/branding', ['primary_color' => '#12'])->assertStatus(422);

    // A script / markup in a free-text field is rejected (never stored/rendered).
    $this->putJson('/api/v1/org/branding', ['brand_name_en' => '<script>alert(1)</script>'])
        ->assertStatus(422);

    // A javascript: asset reference is rejected.
    $this->putJson('/api/v1/org/branding', ['logo' => 'javascript:alert(1)'])->assertStatus(422);
});

it('denies a non-admin user the org brand and domain endpoints', function () {
    $student = User::factory()->create(['organization_id' => 1]);
    $student->assignRole(SpatieRole::findByName('student', 'web'));
    Sanctum::actingAs($student);

    $this->getJson('/api/v1/org/branding')->assertStatus(403);
    $this->putJson('/api/v1/org/branding', ['brand_name_en' => 'Nope'])->assertStatus(403);
    $this->getJson('/api/v1/org/domains')->assertStatus(403);
    $this->postJson('/api/v1/org/domains', ['host' => 'nope.test'])->assertStatus(403);
});
