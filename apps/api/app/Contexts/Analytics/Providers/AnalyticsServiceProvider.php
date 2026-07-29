<?php

namespace App\Contexts\Analytics\Providers;

use App\Contexts\Analytics\Contracts\ExportWriter;
use App\Contexts\Analytics\Contracts\Metric;
use App\Contexts\Analytics\Export\ExportWriterManager;
use App\Contexts\Analytics\Export\Writers\CsvExportWriter;
use App\Contexts\Analytics\Listeners\MetricEventSubscriber;
use App\Contexts\Analytics\Metrics\Providers\SnapshotMetric;
use App\Contexts\Analytics\Models\DashboardDefinition;
use App\Contexts\Analytics\Models\ExportJob;
use App\Contexts\Analytics\Models\MetricSnapshot;
use App\Contexts\Analytics\Models\ReportDefinition;
use App\Contexts\Analytics\Policies\DashboardDefinitionPolicy;
use App\Contexts\Analytics\Policies\ExportJobPolicy;
use App\Contexts\Analytics\Policies\ReportDefinitionPolicy;
use App\Contexts\Analytics\Services\ReportCache;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Wires the Analytics module. This is a consumer domain: it subscribes to producer EVENTS (never
 * their tables) to maintain the read model, and every read goes through metric_snapshots.
 */
class AnalyticsServiceProvider extends BaseDomainServiceProvider
{
    protected array $routeFiles = ['routes/analytics.php'];

    /**
     * DashboardDefinitionPolicy was previously absent here and resolved only through Laravel's
     * convention-based discovery (Models\X -> Policies\XPolicy), which happens to match this
     * namespace layout. It was therefore live — Filament's DashboardResource consults it — but
     * invisibly so, and an audit reading this array alone concluded it was dead code. Registered
     * explicitly so the wiring is stated rather than inferred.
     *
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        ReportDefinition::class => ReportDefinitionPolicy::class,
        ExportJob::class => ExportJobPolicy::class,
        DashboardDefinition::class => DashboardDefinitionPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/analytics.php', 'analytics');

        $this->app->bind(Metric::class, SnapshotMetric::class);
        $this->app->bind(ExportWriter::class, CsvExportWriter::class);
        $this->app->singleton(ExportWriterManager::class, fn ($app) => new ExportWriterManager($app));
    }

    protected function bootDomain(): void
    {
        // Consume producer events into the read model.
        Event::subscribe(MetricEventSubscriber::class);

        // H8 cache invalidation: writing an analytics snapshot means the underlying data advanced,
        // so drop the cached insight reports (a global version bump) and let the next load recompute.
        MetricSnapshot::created(static function (): void {
            app(ReportCache::class)->flush();
        });
    }
}
