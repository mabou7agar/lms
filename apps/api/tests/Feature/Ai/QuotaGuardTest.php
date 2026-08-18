<?php

use App\Platform\AI\Exceptions\AiQuotaExceededException;
use App\Platform\AI\Metering\AiQuotaGuard;
use App\Platform\AI\Models\AiUsage;
use App\Platform\AI\Providers\AiServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(AiServiceProvider::class);
    Artisan::call('migrate', ['--force' => true]);
    config(['ai.enabled' => true]);
    // Isolate one limit per test — everything else unlimited.
    config(['ai.limits' => [
        'max_tokens_per_request' => 0,
        'max_output_tokens' => 0,
        'per_user_daily_tokens' => 0,
        'per_org_monthly_tokens' => 0,
        'global_monthly_tokens' => 0,
    ]]);
});

it('blocks a single request that exceeds the per-request ceiling', function () {
    config(['ai.limits.max_tokens_per_request' => 100]);

    app(AiQuotaGuard::class)->assertWithinLimits(101, null, null);
})->throws(AiQuotaExceededException::class);

it('blocks when the per-user daily token budget is exhausted', function () {
    config(['ai.limits.per_user_daily_tokens' => 500]);
    AiUsage::factory()->create(['user_id' => 9, 'input_tokens' => 400, 'output_tokens' => 200, 'created_at' => now()]);

    // A different user is unaffected (isolation).
    app(AiQuotaGuard::class)->assertWithinLimits(10, null, 10);
    expect(true)->toBeTrue();

    app(AiQuotaGuard::class)->assertWithinLimits(1, null, 9);
})->throws(AiQuotaExceededException::class);

it('blocks when the per-org monthly token budget is exhausted', function () {
    config(['ai.limits.per_org_monthly_tokens' => 500]);
    AiUsage::factory()->create(['organization_id' => 1, 'input_tokens' => 600, 'output_tokens' => 0, 'created_at' => now()]);

    // Another org is unaffected.
    app(AiQuotaGuard::class)->assertWithinLimits(50, 2, null);
    expect(true)->toBeTrue();

    app(AiQuotaGuard::class)->assertWithinLimits(1, 1, null);
})->throws(AiQuotaExceededException::class);

it('records the scope that tripped', function () {
    config(['ai.limits.global_monthly_tokens' => 100]);
    AiUsage::factory()->create(['input_tokens' => 200, 'output_tokens' => 0, 'created_at' => now()]);

    try {
        app(AiQuotaGuard::class)->assertWithinLimits(1, null, null);
        $this->fail('Expected AiQuotaExceededException');
    } catch (AiQuotaExceededException $e) {
        expect($e->scope)->toBe('global_monthly');
    }
});
