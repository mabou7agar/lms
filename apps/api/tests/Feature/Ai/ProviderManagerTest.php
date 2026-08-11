<?php

use App\Platform\AI\AiProviderManager;
use App\Platform\AI\Data\ChatMessage;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Exceptions\AiDisabledException;
use App\Platform\AI\Exceptions\AiProviderDisabledException;
use App\Platform\AI\Exceptions\FakeProviderNotAllowedException;
use App\Platform\AI\Exceptions\ProviderCredentialsRequiredException;
use App\Platform\AI\Exceptions\UnknownAiProviderException;
use App\Platform\AI\Providers\Fake\FakeChatModel;
use App\Platform\AI\Providers\Fake\FakeEmbeddingModel;
use App\Platform\AI\Providers\OpenAi\OpenAiChatModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(\App\Platform\AI\Providers\AiServiceProvider::class);
    Artisan::call('migrate', ['--force' => true]);
    config(['ai.enabled' => true, 'ai.default_provider' => 'fake']);
    // Any accidental network call fails the test — the foundation must be credential-free.
    Http::preventStrayRequests();
});

it('resolves the fake chat + embedding models by default', function () {
    $manager = app(AiProviderManager::class);

    expect($manager->chatModel())->toBeInstanceOf(FakeChatModel::class)
        ->and($manager->chatModel()->provider())->toBe(AiProvider::Fake)
        ->and($manager->embeddingModel())->toBeInstanceOf(FakeEmbeddingModel::class);
});

it('fails closed when AI is globally disabled', function () {
    config(['ai.enabled' => false]);

    app(AiProviderManager::class)->chatModel();
})->throws(AiDisabledException::class);

it('fails closed for an unknown provider', function () {
    app(AiProviderManager::class)->chatModel('does-not-exist');
})->throws(UnknownAiProviderException::class);

it('fails closed for a disabled provider', function () {
    config(['ai.providers.openai.enabled' => false]);

    app(AiProviderManager::class)->chatModel('openai');
})->throws(AiProviderDisabledException::class);

it('refuses the fake provider in production unless AI_ALLOW_FAKE is set', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['ai.default_provider' => 'fake', 'ai.allow_fake' => false]);

    app(AiProviderManager::class)->chatModel();
})->throws(FakeProviderNotAllowedException::class);

it('permits the fake provider in production when AI_ALLOW_FAKE is set', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['ai.default_provider' => 'fake', 'ai.allow_fake' => true]);

    expect(app(AiProviderManager::class)->chatModel())->toBeInstanceOf(FakeChatModel::class);
});

it('resolves a real provider but is LOCAL REQUIRED: it throws without credentials and hits no network', function () {
    config(['ai.providers.openai.enabled' => true, 'ai.providers.openai.api_key' => null]);

    $model = app(AiProviderManager::class)->chatModel('openai');
    expect($model)->toBeInstanceOf(OpenAiChatModel::class);

    $model->chat([ChatMessage::user('hello')], new ModelOptions);
})->throws(ProviderCredentialsRequiredException::class);
