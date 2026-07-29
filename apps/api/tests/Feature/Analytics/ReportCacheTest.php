<?php

use App\Contexts\Analytics\Models\MetricSnapshot;
use App\Contexts\Analytics\Services\ReportCache;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

/**
 * H8 caching contract: a report is served from cache on repeat, keys are discriminated by params and
 * tenant (no cross-request/cross-tenant bleed), and the cache is invalidated on an explicit flush and
 * when an analytics snapshot is written.
 */
it('serves the cached report on a repeat call with identical params', function () {
    $cache = app(ReportCache::class);

    $first = $cache->remember('course_performance', ['2024-01-01', '2024-12-31', 1, 20], fn (): string => 'computed');
    // The closure must NOT run again — a cache hit returns the first value.
    $second = $cache->remember('course_performance', ['2024-01-01', '2024-12-31', 1, 20], fn (): string => 'recomputed');

    expect($first)->toBe('computed')->and($second)->toBe('computed');
});

it('discriminates entries by report params so different windows never collide', function () {
    $cache = app(ReportCache::class);

    $a = $cache->remember('revenue', ['2024-01-01'], fn (): string => 'A');
    $b = $cache->remember('revenue', ['2024-02-01'], fn (): string => 'B');

    expect($a)->toBe('A')->and($b)->toBe('B');
});

it('discriminates entries by tenant so one tenant never reads another tenant\'s report', function () {
    $cache = app(ReportCache::class);

    app(TenantContext::class)->set(TenantId::from(1));
    $tenantOne = $cache->remember('revenue', [1], fn (): string => 'tenant-one');

    app(TenantContext::class)->set(TenantId::from(2));
    $tenantTwo = $cache->remember('revenue', [1], fn (): string => 'tenant-two');

    expect($tenantOne)->toBe('tenant-one')->and($tenantTwo)->toBe('tenant-two');
});

it('recomputes after an explicit flush', function () {
    $cache = app(ReportCache::class);

    $before = $cache->remember('revenue', [1], fn (): string => 'before');
    $cache->flush();
    $after = $cache->remember('revenue', [1], fn (): string => 'after');

    expect($before)->toBe('before')->and($after)->toBe('after');
});

it('invalidates the report cache when an analytics snapshot is written', function () {
    $cache = app(ReportCache::class);

    $before = $cache->remember('revenue', [1], fn (): string => 'before');

    // The MetricSnapshot::created observer flushes the report cache (H8 invalidation on snapshot write).
    MetricSnapshot::factory()->create();

    $after = $cache->remember('revenue', [1], fn (): string => 'after');

    expect($before)->toBe('before')->and($after)->toBe('after');
});
