<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    config(['sso.enabled' => true, 'sso.providers.fake.enabled' => true]);
});

it('mints a redirect URL with a signed state, then logs in on callback', function () {
    $redirect = $this->getJson('/api/v1/auth/social/fake/redirect')->assertOk();

    $state = $redirect->json('data.state');
    expect($state)->toBeString()->not->toBe('')
        ->and($redirect->json('data.authorization_url'))->toContain('fake-sso.test');

    $res = $this->postJson('/api/v1/auth/social/fake/callback', [
        'code' => 'sub-77|hana@ex.test|1|Hana',
        'state' => $state,
    ])->assertOk();

    expect($res->json('data.token'))->toBeString()->not->toBe('')
        ->and($res->json('data.user.id'))->not->toBeNull()
        ->and(User::where('email', 'hana@ex.test')->exists())->toBeTrue();
});

it('rejects a callback with a tampered state', function () {
    $this->postJson('/api/v1/auth/social/fake/callback', ['code' => 'sub-1', 'state' => 'bogus.signature'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SSO_STATE_INVALID');
});

it('requires code and state on callback', function () {
    $this->postJson('/api/v1/auth/social/fake/callback', [])
        ->assertStatus(422);
});

it('returns 404 when SSO is disabled', function () {
    config(['sso.enabled' => false]);

    $this->getJson('/api/v1/auth/social/fake/redirect')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'SSO_DISABLED');
});

it('returns 404 for an unknown provider', function () {
    $this->getJson('/api/v1/auth/social/nope/redirect')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'SSO_PROVIDER_UNKNOWN');
});

it('returns 404 for a configured-but-disabled provider', function () {
    $this->getJson('/api/v1/auth/social/google/redirect')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'SSO_PROVIDER_DISABLED');
});
