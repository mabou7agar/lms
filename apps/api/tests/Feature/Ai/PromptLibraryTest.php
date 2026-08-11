<?php

use App\Platform\AI\AiClient;
use App\Platform\AI\Enums\AiFeature;
use App\Platform\AI\Exceptions\PromptNotFoundException;
use App\Platform\AI\Models\AiUsage;
use App\Platform\AI\Prompts\PromptLibrary;
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

it('resolves the active version and renders its variables', function () {
    $library = app(PromptLibrary::class);
    $library->create([
        'key' => 'greet', 'system_prompt' => 'S', 'user_template' => 'Hi {{ name }}', 'active' => true, 'locale' => 'en',
    ]);

    $rendered = $library->render('greet', ['name' => 'Sam']);

    expect($rendered->version)->toBe(1)
        ->and($rendered->userPrompt)->toBe('Hi Sam');
});

it('throws when no active prompt exists', function () {
    app(PromptLibrary::class)->resolve('missing');
})->throws(PromptNotFoundException::class);

it('duplicates a version into a new inactive draft, leaving the active version unchanged', function () {
    $library = app(PromptLibrary::class);
    $library->create(['key' => 'p', 'user_template' => 'v1', 'active' => true]);

    $copy = $library->duplicate('p', 1);

    expect($copy->version)->toBe(2)
        ->and($copy->active)->toBeFalse()
        ->and($library->resolve('p')->version)->toBe(1);
});

it('captures the immutable prompt version a run used, across rollback', function () {
    $library = app(PromptLibrary::class);
    $library->create(['key' => 'p', 'user_template' => 'v1 {{ x }}', 'active' => true]);
    $library->duplicate('p', 1);          // v2 (inactive)
    $library->activate('p', 2);           // v2 now active

    app(AiClient::class)->chat(AiFeature::Tutor, 'p', ['x' => 'a']);
    expect(AiUsage::query()->orderByDesc('id')->first()->prompt_version)->toBe(2);

    // Roll back to v1 — a subsequent run records v1, not v2.
    $library->rollbackTo('p', 1);
    app(AiClient::class)->chat(AiFeature::Tutor, 'p', ['x' => 'b']);
    expect(AiUsage::query()->orderByDesc('id')->first()->prompt_version)->toBe(1);
});
