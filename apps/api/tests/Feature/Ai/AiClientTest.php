<?php

use App\Platform\AI\AiClient;
use App\Platform\AI\Data\LabeledChatResult;
use App\Platform\AI\Enums\AiFeature;
use App\Platform\AI\Models\AiPrompt;
use App\Platform\AI\Models\AiUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(\App\Platform\AI\Providers\AiServiceProvider::class);
    Artisan::call('migrate', ['--force' => true]);
    config(['ai.enabled' => true, 'ai.default_provider' => 'fake']);
    Http::preventStrayRequests();
});

it('runs a chat through the fake provider and records a usage row with tokens, cost and prompt version', function () {
    // Non-zero pricing so we can assert cost is metered from the returned usage.
    config(['ai.pricing.fake.fake-chat-v1' => ['input' => 1000, 'output' => 1000]]);

    AiPrompt::factory()->create([
        'key' => 'tutor.explain', 'version' => 1, 'active' => true, 'locale' => 'en',
        'system_prompt' => 'You are a tutor.', 'user_template' => 'Explain {{ topic }}.',
    ]);

    $result = app(AiClient::class)->chat(AiFeature::Tutor, 'tutor.explain', ['topic' => 'recursion'], userId: 7);

    expect($result)->toBeInstanceOf(LabeledChatResult::class)
        ->and($result->content)->toContain('recursion')
        ->and($result->label)->toBe('AI-generated')
        ->and($result->promptKey)->toBe('tutor.explain')
        ->and($result->promptVersion)->toBe(1);

    expect(AiUsage::query()->count())->toBe(1);

    $row = AiUsage::query()->firstOrFail();
    expect($row->feature)->toBe(AiFeature::Tutor)
        ->and($row->provider->value)->toBe('fake')
        ->and($row->model)->toBe('fake-chat-v1')
        ->and($row->prompt_key)->toBe('tutor.explain')
        ->and($row->prompt_version)->toBe(1)
        ->and($row->user_id)->toBe(7)
        ->and($row->input_tokens)->toBeGreaterThan(0)
        ->and($row->output_tokens)->toBeGreaterThan(0)
        ->and($row->request_id)->toBe($result->requestId)
        // cost = (in/1000 * 1000) + (out/1000 * 1000) micros == in + out tokens.
        ->and($row->estimated_cost_micros)->toBe($row->input_tokens + $row->output_tokens);
});

it('runs an embedding through the fake provider and records deterministic vectors + usage', function () {
    $result = app(AiClient::class)->embed(['hello world', 'goodbye'], AiFeature::Search, userId: 3);

    expect($result->count())->toBe(2)
        ->and($result->dimensions)->toBe(128)
        ->and($result->provider)->toBe('fake');

    // Deterministic: the same text yields the same vector.
    $again = app(AiClient::class)->embed(['hello world'], AiFeature::Search);
    expect($again->vectors[0])->toBe($result->vectors[0]);

    $rows = AiUsage::query()->where('feature', AiFeature::Search->value)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->first()->input_tokens)->toBeGreaterThan(0);
});

it('writes an audit entry alongside every AI run', function () {
    AiPrompt::factory()->create(['key' => 'tutor.explain', 'version' => 1, 'active' => true]);

    app(AiClient::class)->chat(AiFeature::Tutor, 'tutor.explain', ['topic' => 'x', 'level' => 'beginner']);

    expect(\App\Platform\Shared\Audit\AuditLog::query()->where('action', 'ai.run')->count())->toBe(1);
});
