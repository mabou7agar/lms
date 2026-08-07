<?php

declare(strict_types=1);

namespace App\Platform\Identity\SocialAuth;

use App\Platform\Identity\SocialAuth\Exceptions\InvalidSocialStateException;

/**
 * Stateless, tamper-evident CSRF `state` for the OAuth/OIDC redirect flow.
 *
 * A JSON API has no server session to stash the pending `state`/`nonce` in, so we bind them into a
 * compact token that is HMAC-signed with the application key: `base64url(payload).base64url(sig)`.
 * The callback verifies the signature, the bound provider, and the expiry with no server-side
 * storage. The `nonce` carried inside is what an OIDC id_token must echo back (checked by
 * {@see OidcClaimsValidator}), tying the browser round-trip to the token.
 */
final class SocialStateToken
{
    /**
     * @return array{state: string, nonce: string}
     */
    public function issue(string $provider, string $redirectUri): array
    {
        $nonce = bin2hex(random_bytes(16));

        $payload = [
            'p' => $provider,
            'n' => $nonce,
            'r' => $redirectUri,
            'e' => time() + (int) config('sso.state_ttl', 600),
        ];

        $body = $this->b64((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = $this->b64(hash_hmac('sha256', $body, $this->key(), true));

        return ['state' => $body.'.'.$sig, 'nonce' => $nonce];
    }

    /**
     * @return array{nonce: string, redirect_uri: string}
     *
     * @throws InvalidSocialStateException
     */
    public function verify(string $state, string $provider): array
    {
        $parts = explode('.', $state);
        if (count($parts) !== 2) {
            throw new InvalidSocialStateException;
        }

        [$body, $sig] = $parts;

        $expected = $this->b64(hash_hmac('sha256', $body, $this->key(), true));
        if (! hash_equals($expected, $sig)) {
            throw new InvalidSocialStateException;
        }

        $payload = json_decode($this->unb64($body), true);
        if (! is_array($payload)) {
            throw new InvalidSocialStateException;
        }

        if (($payload['p'] ?? null) !== $provider) {
            throw new InvalidSocialStateException('The sign-in request does not match this provider.');
        }

        if ((int) ($payload['e'] ?? 0) < time()) {
            throw new InvalidSocialStateException('The sign-in request has expired. Please try again.');
        }

        return [
            'nonce' => (string) ($payload['n'] ?? ''),
            'redirect_uri' => (string) ($payload['r'] ?? ''),
        ];
    }

    /** The raw application-key bytes used as the HMAC secret. */
    private function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        return $key;
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function unb64(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
