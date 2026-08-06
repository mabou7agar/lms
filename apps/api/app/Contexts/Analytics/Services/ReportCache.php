<?php

namespace App\Contexts\Analytics\Services;

use App\Platform\Shared\Helpers\LocaleHelper;
use App\Platform\Shared\Tenancy\Contracts\CurrentTenantProvider;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * H8 caching for the insight reports. Extends KpiEngine's `Cache::remember('analytics:'…)` pattern
 * to the expensive multi-table aggregates in ReportingService, which previously re-ran on every
 * dashboard load.
 *
 * Key discrimination:
 *   - TENANT-safe: the active tenant id is part of the key, so a tenant never reads another's
 *     cached figures (the underlying models are tenant-scoped).
 *   - PARAM-safe: the report name + its window/pagination are part of the key, so different
 *     questions never collapse to the same entry.
 *   - PERMISSION-safe: the caller authorizes (admin/super_admin) BEFORE reaching the cache, and the
 *     cached value is tenant-wide aggregate data with no per-user/role variance — so a cached entry
 *     can never leak permission-sensitive or cross-user data.
 *
 * Invalidation is a global version counter folded into every key; bumping it (on snapshot write)
 * orphans every entry at once, and the orphans TTL-expire. TTL bounds staleness for the live-table
 * reports regardless.
 */
class ReportCache
{
    private const VERSION_KEY = 'analytics:report:version';

    public function __construct(private readonly CurrentTenantProvider $tenant) {}

    /**
     * @param  array<int, mixed>  $params
     * @param  Closure(): mixed  $compute
     */
    public function remember(string $report, array $params, Closure $compute): mixed
    {
        return Cache::remember(
            $this->key($report, $params),
            (int) config('analytics.cache.ttl_seconds', 300),
            $compute,
        );
    }

    /** Invalidate every cached report (all tenants) — called when analytics snapshots are written. */
    public function flush(): void
    {
        Cache::add(self::VERSION_KEY, 1);
        Cache::increment(self::VERSION_KEY);
    }

    /** @param  array<int, mixed>  $params */
    private function key(string $report, array $params): string
    {
        $tenant = $this->tenant->currentTenant()?->value ?? 'global';
        $version = (int) Cache::get(self::VERSION_KEY, 1);

        // Locale-scoped: report payloads can embed localized labels, so a cached entry must never
        // be served to a different locale.
        return 'analytics:report:'.$tenant.':'.LocaleHelper::current().':v'.$version.':'.$report.':'.md5(serialize($params));
    }
}
