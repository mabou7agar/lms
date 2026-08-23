<?php

namespace App\Platform\AI\Providers;

use App\Platform\AI\AiClient;
use App\Platform\AI\AiProviderManager;
use App\Platform\AI\Contracts\ChatModel;
use App\Platform\AI\Contracts\EmbeddingModel;
use App\Platform\AI\Governance\AiGovernance;
use App\Platform\AI\Governance\AssessmentAnswerGuard;
use App\Platform\AI\Governance\ContentLabeler;
use App\Platform\AI\Governance\GradingPolicy;
use App\Platform\AI\Governance\ModelRegistry;
use App\Platform\AI\Governance\PromptInjectionGuard;
use App\Platform\AI\Metering\AiQuotaGuard;
use App\Platform\AI\Metering\AiUsageRecorder;
use App\Platform\AI\Metering\AiUsageSummary;
use App\Platform\AI\Metering\CostCalculator;
use App\Platform\AI\Prompts\PromptLibrary;
use App\Platform\AI\Support\TokenEstimator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the provider-neutral AI foundation. Platform-layer module: imports only Shared +
 * IdentityContracts + Laravel. Provider selection is config-driven and defaults to the FAKE
 * provider, so every AI feature and every test runs without credentials; real providers are LOCAL
 * REQUIRED and never call the network unconfigured.
 *
 * NOT registered automatically — add it to bootstrap/providers.php to activate. The Filament
 * resources/widget under Platform/AI/Filament are discovered by adding the path to
 * AdminPanelProvider::RESOURCE_PATHS (integration step), keeping the composition root's discovery
 * list centralized as it is for every other module.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/ai.php', 'ai');

        // Stateless foundation services — one instance each.
        $this->app->singleton(TokenEstimator::class);
        $this->app->singleton(CostCalculator::class);
        $this->app->singleton(ModelRegistry::class);
        $this->app->singleton(AiGovernance::class);
        $this->app->singleton(ContentLabeler::class);
        $this->app->singleton(PromptInjectionGuard::class);
        $this->app->singleton(GradingPolicy::class);
        $this->app->singleton(AssessmentAnswerGuard::class);
        $this->app->singleton(PromptLibrary::class);
        $this->app->singleton(AiUsageRecorder::class);
        $this->app->singleton(AiQuotaGuard::class);
        $this->app->singleton(AiUsageSummary::class);
        $this->app->singleton(AiProviderManager::class);
        $this->app->singleton(AiClient::class);

        // Resolve lightweight delegates here. Provider validation remains fail-closed, but occurs
        // only when chat/embed is called — container inspection and `route:list` must work when AI
        // is intentionally disabled or awaits production credentials.
        $this->app->bind(ChatModel::class, DeferredChatModel::class);
        $this->app->bind(EmbeddingModel::class, DeferredEmbeddingModel::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Tutor + copilot HTTP endpoints. Mounted under the framework 'api' group so they resolve at
        // /api/v1/ai/*, mirroring how SearchServiceProvider mounts the search routes.
        Route::prefix('api')->middleware('api')->group(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/ai.php');
        });
    }
}
