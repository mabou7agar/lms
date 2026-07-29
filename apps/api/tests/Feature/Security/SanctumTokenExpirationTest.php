<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

/**
 * H7 — Sanctum tokens must expire and be prunable. Before this sprint `sanctum.expiration` was
 * null, so a stolen bearer token was valid forever. These pin: config-driven expiry is honored at
 * auth time, the scheduled prune reaps expired tokens while leaving valid ones, and the config
 * value actually drives the cutoff.
 *
 * Real tokens (createToken + Bearer) are used deliberately — Sanctum::actingAs() bypasses token
 * expiration entirely, so it could not exercise this.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function bearer(User $user): string
{
    return $user->createToken('test')->plainTextToken;
}

it('authenticates a valid token before it expires', function () {
    config(['sanctum.expiration' => 60]);
    $token = bearer(User::factory()->create());

    $this->withToken($token)->getJson('/api/v1/my-certificates')->assertOk();
});

it('rejects a token once it has passed the configured expiration', function () {
    config(['sanctum.expiration' => 60]);
    $token = bearer(User::factory()->create());

    // Move past the 60-minute window: the same token must now be refused.
    $this->travel(61)->minutes();

    $this->withToken($token)->getJson('/api/v1/my-certificates')->assertUnauthorized();
});

it('honors a shortened expiration override', function () {
    config(['sanctum.expiration' => 1]);
    $token = bearer(User::factory()->create());

    $this->travel(2)->minutes();

    // A tighter override must take effect — the token is dead after two minutes.
    $this->withToken($token)->getJson('/api/v1/my-certificates')->assertUnauthorized();
});

it('prunes expired tokens', function () {
    // Isolate the explicit-expiry branch so the assertion is version-robust.
    config(['sanctum.expiration' => null]);
    $user = User::factory()->create();

    $expired = $user->createToken('expired')->accessToken;
    $expired->forceFill(['expires_at' => now()->subDay()])->save();

    Artisan::call('sanctum:prune-expired', ['--hours' => 0]);

    expect(PersonalAccessToken::whereKey($expired->getKey())->exists())->toBeFalse();
});

it('preserves unexpired tokens when pruning', function () {
    config(['sanctum.expiration' => null]);
    $user = User::factory()->create();

    $valid = $user->createToken('valid')->accessToken;
    $valid->forceFill(['expires_at' => now()->addDay()])->save();

    Artisan::call('sanctum:prune-expired', ['--hours' => 0]);

    expect(PersonalAccessToken::whereKey($valid->getKey())->exists())->toBeTrue();
});
