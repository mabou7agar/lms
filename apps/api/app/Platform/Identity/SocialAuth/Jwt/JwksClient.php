<?php

declare(strict_types=1);

namespace App\Platform\Identity\SocialAuth\Jwt;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;

/**
 * Fetches and caches a provider's JSON Web Key Set. IdPs rotate signing keys, so the set is fetched
 * from the live `jwks_uri` and cached briefly (keyed by URI) rather than pinned in config.
 */
final class JwksClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly Repository $cache,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function keys(string $jwksUri, int $ttl = 3600): array
    {
        $cached = $this->cache->remember('sso:jwks:'.sha1($jwksUri), $ttl, function () use ($jwksUri): array {
            $body = $this->http->get($jwksUri)->throw()->json();

            $keys = is_array($body) && is_array($body['keys'] ?? null) ? $body['keys'] : [];

            return array_values(array_filter($keys, 'is_array'));
        });

        /** @var array<int, array<string, mixed>> $result */
        $result = is_array($cached) ? $cached : [];

        return $result;
    }
}
