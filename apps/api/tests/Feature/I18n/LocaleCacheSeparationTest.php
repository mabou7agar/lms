<?php

use App\Contexts\Analytics\Models\MetricSnapshot;
use App\Contexts\Analytics\Services\KpiEngine;
use App\Contexts\Analytics\Services\ReportCache;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * Analytics cache keys are locale-scoped: a payload computed under one locale must never be served
 * to another (localized labels can be embedded). Tenant and param discrimination must remain intact.
 */
beforeEach(function () {
    config([
        'shared.locales' => ['en', 'ar'],
        'shared.fallback_locale' => 'en',
        'shared.default_locale' => 'en',
    ]);
    Cache::flush();
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('recomputes a ReportCache entry when the locale changes', function () {
    $cache = app(ReportCache::class);
    $calls = 0;

    app()->setLocale('en');
    $en = $cache->remember('revenue', [1], function () use (&$calls) {
        $calls++;

        return 'value-en';
    });

    app()->setLocale('ar');
    $ar = $cache->remember('revenue', [1], function () use (&$calls) {
        $calls++;

        return 'value-ar';
    });

    // Two distinct entries => the closure ran twice, once per locale.
    expect($calls)->toBe(2)
        ->and($en)->toBe('value-en')
        ->and($ar)->toBe('value-ar');

    // Returning to en serves the original cached entry (closure must NOT run again).
    app()->setLocale('en');
    $enAgain = $cache->remember('revenue', [1], fn (): string => 'should-not-run');

    expect($enAgain)->toBe('value-en');
});

it('keeps tenant discrimination intact alongside locale scoping in ReportCache', function () {
    $cache = app(ReportCache::class);

    app(TenantContext::class)->set(TenantId::from(1));
    app()->setLocale('en');
    $tenantOneEn = $cache->remember('revenue', [1], fn (): string => 't1-en');
    app()->setLocale('ar');
    $tenantOneAr = $cache->remember('revenue', [1], fn (): string => 't1-ar');

    app(TenantContext::class)->set(TenantId::from(2));
    app()->setLocale('en');
    $tenantTwoEn = $cache->remember('revenue', [1], fn (): string => 't2-en');

    // Locale AND tenant both discriminate: three distinct keys, three distinct values.
    expect($tenantOneEn)->toBe('t1-en')
        ->and($tenantOneAr)->toBe('t1-ar')
        ->and($tenantTwoEn)->toBe('t2-en');
});

it('computes a distinct KpiEngine entry per locale', function () {
    $kpi = app(KpiEngine::class);
    $from = CarbonImmutable::parse('2024-06-01');
    $to = CarbonImmutable::parse('2024-06-30');

    app()->setLocale('en');
    MetricSnapshot::factory()->create(['metric_key' => 'enrollments', 'period' => '2024-06-15', 'value' => 5]);

    // en computes and caches 5.
    expect($kpi->total('enrollments', $from, $to))->toBe(5);

    // A new snapshot lands after the en entry was cached. en keeps serving its cached 5 (proving the
    // cache is used); ar has no entry yet, so it recomputes and sees the full 12.
    MetricSnapshot::factory()->create(['metric_key' => 'enrollments', 'period' => '2024-06-16', 'value' => 7]);

    expect($kpi->total('enrollments', $from, $to))->toBe(5);

    app()->setLocale('ar');
    expect($kpi->total('enrollments', $from, $to))->toBe(12);
});
