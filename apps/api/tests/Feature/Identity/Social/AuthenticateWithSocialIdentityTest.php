<?php

use App\Platform\Identity\Actions\Social\AuthenticateWithSocialIdentityAction;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\SocialAccount;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\SocialAuth\Data\SocialIdentity;
use App\Platform\Identity\SocialAuth\Exceptions\SocialEmailConflictException;
use App\Platform\Identity\SocialAuth\Exceptions\SocialEmailRequiredException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function authenticateSocial(SocialIdentity $identity): array
{
    return app(AuthenticateWithSocialIdentityAction::class)->execute($identity);
}

it('creates a new, email-verified student and links the social account', function () {
    $result = authenticateSocial(new SocialIdentity('fake', 'sub-1', 'new@ex.test', true, 'New User'));

    expect($result['token'])->toBeString()->not->toBe('');

    $user = User::where('email', 'new@ex.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasRole('student'))->toBeTrue()
        ->and(SocialAccount::where('provider', 'fake')->where('provider_subject', 'sub-1')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('logs a returning user in via the stable subject, creating no second account', function () {
    $identity = new SocialIdentity('fake', 'sub-2', 'ret@ex.test', true, null);

    $first = authenticateSocial($identity)['user'];
    $second = authenticateSocial($identity)['user'];

    expect($second->id)->toBe($first->id)
        ->and(User::count())->toBe(1)
        ->and(SocialAccount::where('provider_subject', 'sub-2')->count())->toBe(1);
});

it('adopts an existing local account when the provider email is verified', function () {
    $existing = User::factory()->create(['email' => 'link@ex.test']);

    $result = authenticateSocial(new SocialIdentity('fake', 'sub-3', 'link@ex.test', true, null));

    expect($result['user']->id)->toBe($existing->id)
        ->and(User::count())->toBe(1)
        ->and(SocialAccount::where('user_id', $existing->id)->where('provider_subject', 'sub-3')->exists())->toBeTrue();
});

it('refuses to adopt an existing account when the provider email is unverified', function () {
    User::factory()->create(['email' => 'taken@ex.test']);

    expect(fn () => authenticateSocial(new SocialIdentity('fake', 'sub-4', 'taken@ex.test', false, null)))
        ->toThrow(SocialEmailConflictException::class);
});

it('creates an unverified account for an unverified new email', function () {
    $result = authenticateSocial(new SocialIdentity('fake', 'sub-6', 'fresh@ex.test', false, null));

    expect($result['user']->email_verified_at)->toBeNull()
        ->and(User::where('email', 'fresh@ex.test')->exists())->toBeTrue();
});

it('refuses to create an account with no email', function () {
    expect(fn () => authenticateSocial(new SocialIdentity('fake', 'sub-5', null, true, null)))
        ->toThrow(SocialEmailRequiredException::class);
});
