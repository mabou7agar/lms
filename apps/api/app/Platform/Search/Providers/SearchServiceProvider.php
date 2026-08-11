<?php

declare(strict_types=1);

namespace App\Platform\Search\Providers;

use App\Platform\AI\Contracts\EmbeddingModel;
use App\Platform\Search\Console\BackfillSearchIndexCommand;
use App\Platform\Search\Contracts\VectorStore;
use App\Platform\Search\Ingestion\IngestionService;
use App\Platform\Search\Ingestion\QueueingSearchIndexer;
use App\Platform\Search\Search\HybridSearchService;
use App\Platform\Search\Stores\PgVectorStore;
use App\Platform\Search\Stores\PortableVectorStore;
use App\Platform\Search\Support\TextCanonicalizer;
use App\Platform\Shared\Search\Contracts\SearchIndexer;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Search platform capability: config, the content_embeddings migration, the search routes,
 * the vector-store driver selection (portable default / pgvector guarded stub), the hybrid search +
 * ingestion services, the async embedding queue hook, and the backfill command.
 *
 * Platform-layer module (Deptrac: Search -> Shared + IdentityContracts). It additionally imports the
 * AI EmbeddingModel CONTRACT + uses config('ai.defaults') — a legitimate Search -> AI edge the
 * integrator adds to deptrac.yaml. It never references a concrete AI provider.
 *
 * NOT auto-registered: add to bootstrap/providers.php AFTER AiServiceProvider (Search resolves the
 * EmbeddingModel contract AI binds) and after the content domains (Catalog/Authoring/Qna) so their
 * `search.indexers`-tagged adapters are discoverable by the ingestion service.
 */
final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/search.php', 'search');

        $this->app->singleton(TextCanonicalizer::class);

        // Driver selection: portable (default, no extension) or the pgvector guarded stub.
        $this->app->singleton(VectorStore::class, function ($app): VectorStore {
            return (string) config('search.vector.driver', 'portable') === 'pgvector'
                ? $app->make(PgVectorStore::class)
                : $app->make(PortableVectorStore::class);
        });

        // Ingestion pulls from every domain adapter tagged `search.indexers`. Bound (not singleton)
        // so the tag set is resolved fresh after all domain providers have registered.
        $this->app->bind(IngestionService::class, function ($app): IngestionService {
            /** @var iterable<\App\Platform\Shared\Search\Contracts\IndexableContentPort> $tagged */
            $tagged = $app->tagged('search.indexers');

            return new IngestionService(
                $app->make(EmbeddingModel::class),
                $app->make(TextCanonicalizer::class),
                array_values(iterator_to_array($tagged)),
            );
        });

        $this->app->singleton(HybridSearchService::class);

        // The write-side hook a content domain calls; replaces Shared's NullSearchIndexer default.
        $this->app->bind(SearchIndexer::class, QueueingSearchIndexer::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::prefix('api')->middleware('api')->group(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/search.php');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([BackfillSearchIndexCommand::class]);
        }
    }
}
