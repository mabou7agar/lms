<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Enterprise\Contracts\OrganizationSubscriptionPort;
use App\Platform\Shared\Enterprise\Data\OrganizationSeatSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    // Self-contained fake for the Shared seat/subscription port so the developer read surface is
    // exercised without booting Commerce.
    $this->app->bind(OrganizationSubscriptionPort::class, fn () => fakeSeatPort());
});

/** Fixed seat/subscription summary (50 purchased, 30 used, 20 available). */
function fakeSeatPort(): OrganizationSubscriptionPort
{
    return new class implements OrganizationSubscriptionPort
    {
        public function seatSummary(int $organizationId): ?OrganizationSeatSummary
        {
            return new OrganizationSeatSummary('sub_ABC', 'active', 50, 30, 20);
        }

        public function resizeSeats(int $organizationId, int $newSeats): bool
        {
            return true;
        }
    };
}

/** @return array{0: User, 1: string} */
function scopedToken(array $scopes, ?int $organizationId = 1): array
{
    $user = User::factory()->create(['organization_id' => $organizationId]);

    return [$user, $user->createToken('dev key', $scopes)->plainTextToken];
}

it('allows an in-scope token to read the developer endpoint (200)', function () {
    [, $token] = scopedToken(['org:read']);

    $this->withToken($token)->getJson('/api/v1/developer/organization')
        ->assertOk()
        ->assertJsonPath('data.subscription.status', 'active');
});

it('serves seat and usage data from the shared port', function () {
    [, $token] = scopedToken(['seats:read', 'usage:read']);

    $this->withToken($token)->getJson('/api/v1/developer/seats')
        ->assertOk()
        ->assertJsonPath('data.seats.purchased', 50)
        ->assertJsonPath('data.seats.used', 30);

    $this->withToken($token)->getJson('/api/v1/developer/usage')
        ->assertOk()
        ->assertJsonPath('data.utilization', 0.6);
});

it('rejects an out-of-scope token with 403', function () {
    [, $token] = scopedToken(['account:read']);

    $this->withToken($token)->getJson('/api/v1/developer/organization')->assertStatus(403);
});

it('lets an account:read token read its own account', function () {
    [$user, $token] = scopedToken(['account:read']);

    $this->withToken($token)->getJson('/api/v1/developer/account')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email);
});

it('confines a scoped developer key to the developer surface (403 on a first-party route)', function () {
    // A scoped key is a valid Sanctum bearer, but must NOT act as a full-access session token on the
    // first-party API. Without EnforceApiTokenScope it would silently exercise the owner's permissions
    // on every other auth:sanctum route (logout/profile/devices/…). It stays confined to /developer/*.
    [, $token] = scopedToken(['account:read']);

    $response = $this->withToken($token)->postJson('/api/v1/auth/logout')->assertStatus(403);
    expect($response->getContent())->toContain('TOKEN_SCOPE_FORBIDDEN');
});

it('rejects a revoked token with 401', function () {
    [$user, $token] = scopedToken(['org:read']);

    $user->tokens()->delete();

    $this->withToken($token)->getJson('/api/v1/developer/organization')->assertStatus(401);
});

it('honours token expiry with 401', function () {
    $user = User::factory()->create(['organization_id' => 1]);
    $new = $user->createToken('expiring', ['org:read']);
    $new->accessToken->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->withToken($new->plainTextToken)->getJson('/api/v1/developer/organization')->assertStatus(401);
});

it('stamps last_used_at after a call', function () {
    $user = User::factory()->create(['organization_id' => 1]);
    $new = $user->createToken('tracked', ['account:read']);

    expect($new->accessToken->fresh()->last_used_at)->toBeNull();

    $this->withToken($new->plainTextToken)->getJson('/api/v1/developer/account')->assertOk();

    expect($new->accessToken->fresh()->last_used_at)->not->toBeNull();
});

it('never leaks the token hash or plaintext in a read response', function () {
    [, $token] = scopedToken(['account:read']);

    $response = $this->withToken($token)->getJson('/api/v1/developer/account')->assertOk();

    expect($response->getContent())->not->toContain($token);
    expect(array_keys($response->json('data')))->not->toContain('token');
});
