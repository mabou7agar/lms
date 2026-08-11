<?php

declare(strict_types=1);

namespace App\Platform\Integration\Security;

use App\Platform\Integration\Exceptions\WebhookUrlNotAllowedException;

/**
 * SSRF defense for outbound webhook URLs. Enforced BOTH at registration (fail fast for the user) and
 * again immediately before every delivery (a host's DNS can be re-pointed to an internal address
 * between registration and delivery — TOCTOU — so the pre-flight check is the authoritative one).
 *
 * Rejects:
 *   - non-http(s) schemes (file://, gopher://, ftp://, ...),
 *   - plaintext http when HTTPS is required (production),
 *   - any host that resolves to a private (RFC1918 / fc00::/7), loopback (127/8, ::1),
 *     link-local (169.254/16 — incl. the 169.254.169.254 cloud metadata endpoint) or otherwise
 *     reserved address.
 *
 * DNS is resolved here so a public hostname pointing at an internal IP is caught. A host that does
 * not resolve (e.g. a reserved *.test hostname in the test suite) cannot be proven internal and is
 * allowed — HTTPS + the receiver's own signature check remain as defense in depth.
 */
final class WebhookUrlGuard
{
    public function __construct(private readonly bool $requireHttps) {}

    /**
     * @throws WebhookUrlNotAllowedException
     */
    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw WebhookUrlNotAllowedException::reason('invalid_url');
        }

        $scheme = strtolower((string) $parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw WebhookUrlNotAllowedException::reason('unsupported_scheme');
        }

        if ($this->requireHttps && $scheme !== 'https') {
            throw WebhookUrlNotAllowedException::reason('https_required');
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw WebhookUrlNotAllowedException::reason('private_host');
        }

        // Reject alternate integer/hex/octal IP encodings (2130706433, 0x7f000001, 0177.0.0.1) that
        // FILTER_VALIDATE_IP won't accept as a dotted IP but curl/libc will still dial as a literal —
        // otherwise they slip through the DNS path unresolved and are allowed.
        if ($this->isNumericHostLiteral($host)) {
            throw WebhookUrlNotAllowedException::reason('private_host');
        }

        foreach ($this->resolveIps($host) as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw WebhookUrlNotAllowedException::reason('private_host');
            }
        }
    }

    public function isAllowed(string $url): bool
    {
        try {
            $this->assertAllowed($url);

            return true;
        } catch (WebhookUrlNotAllowedException) {
            return false;
        }
    }

    /**
     * Resolve a host to the set of IPs it points at. An IP literal is returned as-is; a hostname is
     * resolved via DNS (A + AAAA). An unresolvable host yields an empty set (cannot be proven internal).
     *
     * @return list<string>
     */
    private function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $resolved;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * True when the IP (or, for an IPv4-mapped/compat IPv6, its embedded IPv4) is private, loopback,
     * link-local or otherwise reserved — not internet-routable.
     */
    private function isBlockedIp(string $ip): bool
    {
        foreach ($this->candidateIps($ip) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The IP plus, when it is an IPv4-mapped (::ffff:a.b.c.d) or IPv4-compatible (::a.b.c.d) IPv6
     * literal, the embedded IPv4 — so a private v4 smuggled inside a v6 literal is still caught.
     *
     * @return list<string>
     */
    private function candidateIps(string $ip): array
    {
        $candidates = [$ip];

        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            $prefix = substr($packed, 0, 12);
            $mapped = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
            $compat = str_repeat("\x00", 12);

            if ($prefix === $mapped || $prefix === $compat) {
                $v4 = @inet_ntop(substr($packed, 12, 4));
                if (is_string($v4) && filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $candidates[] = $v4;
                }
            }
        }

        return $candidates;
    }

    /**
     * True when the host is an alternate integer/hex/octal encoding of an IP (every dot-separated
     * label is a decimal/hex/octal number) rather than a real hostname — a genuine dotted/colon IP
     * literal returns false here and is validated by {@see isBlockedIp} instead.
     */
    private function isNumericHostLiteral(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return (bool) preg_match('/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*$/i', $host);
    }
}
