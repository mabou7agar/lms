<?php

namespace App\Platform\Notifications\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Idempotent counters + latency accumulation for notification delivery outcomes. Backed by the
 * cache store (atomic increments), so it works across web and queue workers and feeds real-time
 * dashboards / log-based alerting. The Filament widget reads authoritative counts from the database;
 * this exists for real-time signal and for asserting the observability contract in tests.
 *
 * Idempotency: every increment is guarded by a per-(metric, token) marker, so re-processing the same
 * transition (e.g. an event re-fired after a worker restart) never double-counts. Retries pass a
 * token that includes the attempt number, so each distinct attempt is counted exactly once.
 */
class NotificationMetrics
{
    private const PREFIX = 'notifications:metrics';

    /** Rolling retention for the ephemeral counters (the DB remains the authoritative ledger). */
    private const TTL_DAYS = 7;

    public function __construct(private readonly CacheRepository $cache) {}

    public function increment(string $metric, ?string $channel, string $token): void
    {
        if (! $this->claim("{$metric}:{$token}")) {
            return;
        }

        $this->bump(self::key($metric));

        if ($channel !== null) {
            $this->bump(self::key($metric, $channel));
        }
    }

    public function observeLatency(int $milliseconds, string $token): void
    {
        if (! $this->claim("latency:{$token}")) {
            return;
        }

        $this->bump(self::PREFIX.':latency:count');
        $this->bump(self::PREFIX.':latency:sum', max(0, $milliseconds));
    }

    public function count(string $metric, ?string $channel = null): int
    {
        return (int) $this->cache->get(self::key($metric, $channel), 0);
    }

    public function latencyCount(): int
    {
        return (int) $this->cache->get(self::PREFIX.':latency:count', 0);
    }

    public function averageLatencyMs(): ?float
    {
        $count = $this->latencyCount();

        if ($count === 0) {
            return null;
        }

        return (int) $this->cache->get(self::PREFIX.':latency:sum', 0) / $count;
    }

    /** First caller for a given token wins; subsequent calls are no-ops (idempotency guard). */
    private function claim(string $token): bool
    {
        return $this->cache->add(self::PREFIX.":seen:{$token}", 1, now()->addDays(self::TTL_DAYS));
    }

    private function bump(string $key, int $by = 1): void
    {
        $this->cache->add($key, 0, now()->addDays(self::TTL_DAYS));
        $this->cache->increment($key, $by);
    }

    private static function key(string $metric, ?string $channel = null): string
    {
        return $channel === null
            ? self::PREFIX.":count:{$metric}"
            : self::PREFIX.":count:{$metric}:{$channel}";
    }
}
