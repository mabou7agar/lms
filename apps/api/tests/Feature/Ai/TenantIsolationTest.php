<?php

use App\Platform\AI\AiClient;
use App\Platform\AI\Enums\AiFeature;
use App\Platform\AI\Exceptions\AiQuotaExceededException;
use App\Platform\AI\Models\AiPrompt;
use App\Platform\AI\Models\AiUsage;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(\App\Platform\AI\Providers\AiServiceProvider::class);
    Artisan::call('migrate', ['--force' => true]);
    config(['ai.enabled' => true, 'ai.default_provider' => 'fake']);
    Http::preventStrayRequests();
    AiPrompt::factory()->create(['key' => 'p', 'user_template' => 'Hi {{ x }}', 'active' => true]);
});

it('isolates usage rows per tenant', function () {
    $context = app(TenantContext::class);

    $context->set(TenantId::from(1));
    app(AiClient::class)->chat(AiFeature::Tutor, 'p', ['x' => 'a']);

    $context->set(TenantId::from(2));
    app(AiClient::class)->chat(AiFeature::Tutor, 'p', ['x' => 'b']);

    // Each tenant sees only its own row through the global scope.
    $context->set(TenantId::from(1));
    expect(AiUsage::query()->count())->toBe(1)
        ->and(AiUsage::query()->first()->organization_id)->toBe(1);

    $context->set(TenantId::from(2));
    expect(AiUsage::query()->count())->toBe(1)
        ->and(AiUsage::query()->first()->organization_id)->toBe(2);

    // Platform-wide (explicit bypass) sees both.
    $total = $context->runWithoutTenancy(fn (): int => AiUsage::query()->count());
    expect($total)->toBe(2);
});

it('scopes org monthly quota to the tenant so one org cannot exhaust another', function () {
    config(['ai.limits' => [
        'max_tokens_per_request' => 0,
        'max_output_tokens' => 0,
        'per_user_daily_tokens' => 0,
        'per_org_monthly_tokens' => 500,
        'global_monthly_tokens' => 0,
    ]]);
    // Keep the per-call estimate small so a single call fits under the 500-token org budget.
    config(['ai.defaults.max_tokens' => 50]);

    // Org 1 is already over budget.
    AiUsage::factory()->create(['organization_id' => 1, 'input_tokens' => 600, 'output_tokens' => 0, 'created_at' => now()]);

    $context = app(TenantContext::class);

    // Org 2 is unaffected and can run.
    $context->set(TenantId::from(2));
    $result = app(AiClient::class)->chat(AiFeature::Tutor, 'p', ['x' => 'ok']);
    expect($result->promptKey)->toBe('p');

    // Org 1 is blocked.
    $context->set(TenantId::from(1));
    app(AiClient::class)->chat(AiFeature::Tutor, 'p', ['x' => 'no']);
})->throws(AiQuotaExceededException::class);
