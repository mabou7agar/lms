<?php

use App\Platform\Identity\Models\SocialAccount;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function linkProvider(User $user, string $provider, string $subject, string $email): SocialAccount
{
    return SocialAccount::create([
        'user_id' => $user->id,
        'provider' => $provider,
        'provider_subject' => $subject,
        'email' => $email,
    ]);
}

it('lists the caller linked accounts without leaking tokens or subject ids', function () {
    $user = User::factory()->create();
    linkProvider($user, 'google', 'g-sub-1', 'me@ex.test');
    linkProvider($user, 'microsoft', 'ms-sub-1', 'me@work.test');

    Sanctum::actingAs($user);

    $res = $this->getJson('/api/v1/account/linked-accounts')->assertOk();

    expect($res->json('data.accounts'))->toHaveCount(2)
        ->and($res->json('data.has_password'))->toBeTrue()
        ->and($res->json('data.accounts.0'))->toHaveKeys(['id', 'provider', 'email', 'linked_at'])
        ->and($res->json('data.accounts.0'))->not->toHaveKey('provider_subject')
        ->and($res->json('data.accounts.0'))->not->toHaveKey('token');
});

it('reports has_password false for a social-only account', function () {
    $user = User::factory()->socialOnly()->create();
    linkProvider($user, 'google', 'g-sub-x', 'me@ex.test');

    Sanctum::actingAs($user);

    expect($this->getJson('/api/v1/account/linked-accounts')->json('data.has_password'))->toBeFalse();
});

it('unlinks a provider when the account still has a password', function () {
    $user = User::factory()->create(); // password-capable by default
    $account = linkProvider($user, 'google', 'g-sub-2', 'me@ex.test');

    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/account/linked-accounts/{$account->public_id}")->assertOk();

    expect($user->fresh()->socialAccounts()->count())->toBe(0);
});

it('refuses to unlink the last sign-in method of a social-only account (never orphan)', function () {
    $user = User::factory()->socialOnly()->create();
    $account = linkProvider($user, 'google', 'g-sub-3', 'me@ex.test');

    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/account/linked-accounts/{$account->public_id}")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SSO_LAST_METHOD');

    // The credential must remain — the account was not orphaned.
    expect($user->fresh()->socialAccounts()->count())->toBe(1);
});

it('allows a social-only account with two providers to unlink one', function () {
    $user = User::factory()->socialOnly()->create();
    $keep = linkProvider($user, 'google', 'g-sub-4', 'me@ex.test');
    $drop = linkProvider($user, 'microsoft', 'ms-sub-4', 'me@work.test');

    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/account/linked-accounts/{$drop->public_id}")->assertOk();

    expect($user->fresh()->socialAccounts()->pluck('id')->all())->toBe([$keep->id]);
});

it('cannot unlink another user linked account', function () {
    $owner = User::factory()->create();
    $account = linkProvider($owner, 'google', 'g-sub-5', 'owner@ex.test');

    Sanctum::actingAs(User::factory()->create());

    $this->deleteJson("/api/v1/account/linked-accounts/{$account->public_id}")->assertStatus(403);

    expect($owner->fresh()->socialAccounts()->count())->toBe(1);
});
