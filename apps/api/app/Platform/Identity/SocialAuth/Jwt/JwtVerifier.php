<?php

declare(strict_types=1);

namespace App\Platform\Identity\SocialAuth\Jwt;

use App\Platform\Identity\SocialAuth\Exceptions\InvalidSocialClaimsException;

/**
 * Verifies a signed JWT (an OIDC id_token) against a JWKS using only ext-openssl — no third-party
 * JWT package. Supports the two algorithms the supported IdPs use: RS256 (Google/Microsoft/Apple
 * id_tokens) and ES256 (kept for completeness / our own Apple client secret).
 *
 * It checks ONLY the cryptographic signature (that the token was minted by the holder of the JWKS
 * private key) and returns the decoded claims. Semantic checks — issuer, audience, nonce, expiry —
 * are the OidcClaimsValidator's job, called by the provider immediately after. Keeping the two apart
 * means neither can be skipped by accident.
 */
final class JwtVerifier
{
    private const SUPPORTED = ['RS256', 'ES256'];

    /**
     * @param  array<int, array<string, mixed>>  $jwks  the provider's JWKS `keys` array
     * @return array<string, mixed> the signature-verified claims
     *
     * @throws InvalidSocialClaimsException
     */
    public function verify(string $jwt, array $jwks): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new InvalidSocialClaimsException('format');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode($this->decode($headerB64), true);
        $payload = json_decode($this->decode($payloadB64), true);
        if (! is_array($header) || ! is_array($payload)) {
            throw new InvalidSocialClaimsException('format');
        }

        $alg = isset($header['alg']) ? (string) $header['alg'] : '';
        if (! in_array($alg, self::SUPPORTED, true)) {
            throw new InvalidSocialClaimsException('alg');
        }

        $jwk = $this->selectKey($jwks, isset($header['kid']) ? (string) $header['kid'] : '', $alg);
        if ($jwk === null) {
            throw new InvalidSocialClaimsException('kid');
        }

        $signature = $this->decode($signatureB64);
        if ($alg === 'ES256') {
            $signature = Der::ecSignatureToDer($signature);
        }

        $verified = openssl_verify($headerB64.'.'.$payloadB64, $signature, $this->publicKeyPem($jwk, $alg), OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new InvalidSocialClaimsException('signature');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $jwks
     * @return array<string, mixed>|null
     */
    private function selectKey(array $jwks, string $kid, string $alg): ?array
    {
        $wantKty = $alg === 'ES256' ? 'EC' : 'RSA';

        foreach ($jwks as $jwk) {
            if (($jwk['kty'] ?? null) !== $wantKty) {
                continue;
            }
            if ($kid === '' || ($jwk['kid'] ?? null) === $kid) {
                return $jwk;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $jwk
     */
    private function publicKeyPem(array $jwk, string $alg): string
    {
        if ($alg === 'ES256') {
            $x = str_pad(substr($this->decode((string) ($jwk['x'] ?? '')), -32), 32, "\x00", STR_PAD_LEFT);
            $y = str_pad(substr($this->decode((string) ($jwk['y'] ?? '')), -32), 32, "\x00", STR_PAD_LEFT);

            $algorithmId = Der::sequence(
                "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"          // id-ecPublicKey
                ."\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07",    // prime256v1
            );
            $spki = Der::sequence($algorithmId.Der::bitString("\x04".$x.$y));

            return $this->pem($spki);
        }

        $n = Der::integer($this->decode((string) ($jwk['n'] ?? '')));
        $e = Der::integer($this->decode((string) ($jwk['e'] ?? '')));

        $algorithmId = Der::sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"."\x05\x00"); // rsaEncryption, NULL
        $spki = Der::sequence($algorithmId.Der::bitString(Der::sequence($n.$e)));

        return $this->pem($spki);
    }

    private function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n").'-----END PUBLIC KEY-----';
    }

    private function decode(string $b64url): string
    {
        $padded = strtr($b64url, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        return (string) base64_decode($padded, true);
    }
}
