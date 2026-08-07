<?php

declare(strict_types=1);

namespace App\Platform\Identity\SocialAuth\Apple;

use App\Platform\Identity\SocialAuth\Jwt\Der;
use RuntimeException;

/**
 * Builds the short-lived ES256 JWT that "Sign in with Apple" requires in place of a static client
 * secret at the token endpoint. Signed with the team's .p8 private key (base64-encoded PEM in config)
 * using ext-openssl; the DER signature openssl emits is converted to the JOSE raw r‖s form.
 *
 * Real signing needs the actual Apple private key + team/key/client ids (LOCAL REQUIRED); the signer
 * itself is verifiable in CI by signing with a generated EC key and checking the signature.
 */
final class AppleClientSecret
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function generate(?int $now = null): string
    {
        $now ??= time();

        $header = ['alg' => 'ES256', 'kid' => (string) ($this->config['key_id'] ?? ''), 'typ' => 'JWT'];
        $payload = [
            'iss' => (string) ($this->config['team_id'] ?? ''),
            'iat' => $now,
            'exp' => $now + 3600,
            'aud' => 'https://appleid.apple.com',
            'sub' => (string) ($this->config['client_id'] ?? ''),
        ];

        $signingInput = $this->b64((string) json_encode($header)).'.'.$this->b64((string) json_encode($payload));

        $privateKey = openssl_pkey_get_private($this->privateKeyPem());
        if ($privateKey === false) {
            throw new RuntimeException('The Apple sign-in private key is invalid.');
        }

        $der = '';
        if (openssl_sign($signingInput, $der, $privateKey, OPENSSL_ALGO_SHA256) !== true) {
            throw new RuntimeException('Failed to sign the Apple client secret.');
        }

        return $signingInput.'.'.$this->b64(Der::ecSignatureFromDer($der));
    }

    /** The PEM of the .p8 key. Config stores it base64-encoded; accept a raw PEM too. */
    private function privateKeyPem(): string
    {
        $key = (string) ($this->config['private_key'] ?? '');

        if (! str_contains($key, 'BEGIN')) {
            $key = (string) base64_decode($key, true);
        }

        return $key;
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
