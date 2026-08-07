<?php

declare(strict_types=1);

namespace App\Platform\Identity\SocialAuth\Jwt;

use RuntimeException;

/**
 * Minimal ASN.1 DER primitives — just enough to (a) assemble a SubjectPublicKeyInfo PEM from a JWK's
 * raw key material so openssl can verify a token, and (b) convert an ECDSA signature between the JOSE
 * "raw r‖s" form and the DER SEQUENCE{r,s} form openssl uses. This lets us do real RS256/ES256
 * verification with only ext-openssl (already required) and no third-party JWT package.
 *
 * All inputs/outputs are raw binary strings. Not a general ASN.1 codec — only the shapes JOSE needs.
 */
final class Der
{
    /** DER definite-length prefix. */
    public static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    public static function sequence(string $content): string
    {
        return "\x30".self::length(strlen($content)).$content;
    }

    /** DER INTEGER from a big-endian magnitude, encoded as a non-negative value. */
    public static function integer(string $magnitude): string
    {
        $magnitude = ltrim($magnitude, "\x00");

        if ($magnitude === '') {
            $magnitude = "\x00";
        }

        if ((ord($magnitude[0]) & 0x80) !== 0) {
            $magnitude = "\x00".$magnitude; // keep the value positive
        }

        return "\x02".self::length(strlen($magnitude)).$magnitude;
    }

    /** DER BIT STRING with zero unused bits. */
    public static function bitString(string $content): string
    {
        return "\x03".self::length(strlen($content) + 1)."\x00".$content;
    }

    /** JOSE raw ECDSA signature (r‖s, each `$partLen` bytes) → DER SEQUENCE{ INTEGER r, INTEGER s }. */
    public static function ecSignatureToDer(string $raw, int $partLen = 32): string
    {
        if (strlen($raw) !== $partLen * 2) {
            throw new RuntimeException('Unexpected ECDSA signature length.');
        }

        return self::sequence(
            self::integer(substr($raw, 0, $partLen)).self::integer(substr($raw, $partLen, $partLen)),
        );
    }

    /** DER SEQUENCE{ INTEGER r, INTEGER s } → JOSE raw ECDSA signature (r‖s, each `$partLen` bytes). */
    public static function ecSignatureFromDer(string $der, int $partLen = 32): string
    {
        $offset = 0;

        self::expect($der, $offset, 0x30); // SEQUENCE
        self::readLength($der, $offset);

        $r = self::readInteger($der, $offset);
        $s = self::readInteger($der, $offset);

        return self::pad($r, $partLen).self::pad($s, $partLen);
    }

    private static function expect(string $der, int &$offset, int $tag): void
    {
        if (! isset($der[$offset]) || ord($der[$offset]) !== $tag) {
            throw new RuntimeException('Malformed DER structure.');
        }

        $offset++;
    }

    private static function readLength(string $der, int &$offset): int
    {
        $first = ord($der[$offset++]);

        if ($first < 0x80) {
            return $first;
        }

        $count = $first & 0x7f;
        $length = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | ord($der[$offset++]);
        }

        return $length;
    }

    private static function readInteger(string $der, int &$offset): string
    {
        self::expect($der, $offset, 0x02); // INTEGER
        $length = self::readLength($der, $offset);
        $value = substr($der, $offset, $length);
        $offset += $length;

        return ltrim($value, "\x00");
    }

    private static function pad(string $value, int $length): string
    {
        return str_pad(substr($value, -$length), $length, "\x00", STR_PAD_LEFT);
    }
}
