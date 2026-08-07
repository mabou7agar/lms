<?php

namespace App\Platform\Identity\Actions\Social;

use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Events\UserLoggedIn;
use App\Platform\Identity\Models\SocialAccount;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Services\DeviceService;
use App\Platform\Identity\SocialAuth\Data\SocialIdentity;
use App\Platform\Identity\SocialAuth\Exceptions\SocialEmailConflictException;
use App\Platform\Identity\SocialAuth\Exceptions\SocialEmailRequiredException;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Support\Str;

/**
 * Turns a verified {@see SocialIdentity} into an authenticated session, then issues a Sanctum token
 * through the SAME device-registration path as password login so social sessions are revocable
 * exactly like any other.
 *
 * Matching order is security-first:
 *   1. An existing link on (provider, subject) — the stable key — logs that user straight in.
 *   2. Otherwise, a VERIFIED provider email may adopt an existing local account (linking it) or mint
 *      a fresh, already-email-verified account.
 *   3. A provider email that is present but UNVERIFIED never adopts an existing account (that would
 *      be account takeover) — it only creates a new, unverified account; if one already exists on
 *      that email the request is refused.
 *   4. No email at all is refused, since an account cannot be created without one.
 */
class AuthenticateWithSocialIdentityAction extends BaseAction
{
    public function __construct(private readonly DeviceService $devices) {}

    /**
     * @param  array{ip?: ?string, user_agent?: ?string}  $meta
     * @return array{user: User, token: string}
     */
    public function execute(SocialIdentity $identity, array $meta = []): array
    {
        $user = $this->transaction(fn (): User => $this->resolveUser($identity));

        $token = $this->transaction(function () use ($user, $meta): string {
            $newToken = $user->createToken('social');
            $this->devices->register(
                $user,
                $newToken,
                'social',
                $meta['ip'] ?? null,
                $meta['user_agent'] ?? null,
            );

            return $newToken->plainTextToken;
        });

        UserLoggedIn::dispatch($user);

        return ['user' => $user, 'token' => $token];
    }

    private function resolveUser(SocialIdentity $identity): User
    {
        $account = SocialAccount::query()
            ->where('provider', $identity->provider)
            ->where('provider_subject', $identity->subject)
            ->first();

        if ($account !== null && ($linked = $account->user) instanceof User) {
            if ($identity->email !== null && $account->email !== $identity->email) {
                $account->forceFill(['email' => $identity->email])->save();
            }

            return $linked;
        }

        $email = $identity->email;
        if ($email === null || $email === '') {
            throw new SocialEmailRequiredException;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            if (! $identity->emailVerified) {
                throw new SocialEmailConflictException;
            }

            $this->linkAccount($existing, $identity);

            return $existing;
        }

        return $this->createUser($identity, $email);
    }

    private function createUser(SocialIdentity $identity, string $email): User
    {
        $user = User::create([
            'name' => $identity->name ?? $this->nameFromEmail($email),
            'email' => $email,
            // Random, unknown-to-anyone password (hashed by the model cast). Social users authenticate
            // through the provider; they can set a password later via the normal reset flow.
            'password' => Str::password(40),
            'locale' => (string) config('shared.default_locale', 'en'),
            'is_active' => true,
        ]);

        if ($identity->emailVerified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->profile()->create([]);
        $user->assignRole(Role::Student->value);
        $this->linkAccount($user, $identity);

        return $user;
    }

    private function linkAccount(User $user, SocialIdentity $identity): void
    {
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $identity->provider,
            'provider_subject' => $identity->subject,
            'email' => $identity->email,
        ]);
    }

    private function nameFromEmail(string $email): string
    {
        $local = Str::before($email, '@');

        return Str::of($local)->replace(['.', '_', '-'], ' ')->title()->trim()->toString() ?: 'Member';
    }
}
