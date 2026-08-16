<?php

namespace App\Platform\Notifications\Providers;

use App\Platform\Notifications\Channels\ProviderManager;
use App\Platform\Notifications\Contracts\Providers\MailProvider;
use App\Platform\Notifications\Contracts\Providers\PushProvider;
use App\Platform\Notifications\Contracts\Providers\SmsProvider;
use App\Platform\Notifications\Jobs\AdvanceDripCampaignsJob;
use App\Platform\Notifications\Listeners\NotificationEventSubscriber;
use App\Platform\Notifications\Listeners\NotificationTelemetrySubscriber;
use App\Platform\Notifications\Models\Notification;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Observers\NotificationDeliveryObserver;
use App\Platform\Notifications\Policies\NotificationPolicy;
use App\Platform\Notifications\Services\AutomationEventCatalog;
use App\Platform\Notifications\Services\AutomationRunner;
use App\Platform\Notifications\Services\ExpiryNotificationService;
use App\Platform\Notifications\Services\LearningNotificationService;
use App\Platform\Shared\Marketing\Contracts\MarketingAudiencePort;
use App\Platform\Shared\Marketing\NullMarketingAudiencePort;
use App\Platform\Shared\Notifications\Contracts\ExpiryNotificationPort;
use App\Platform\Shared\Notifications\Contracts\LearningNotificationPort;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

/**
 * Wires the Notifications module. Consumer domain: subscribes to producer EVENTS (never their
 * tables) and dispatches queued deliveries via Fake channel/provider abstractions only.
 */
class NotificationsServiceProvider extends BaseDomainServiceProvider
{
    protected array $routeFiles = ['routes/notifications.php'];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        Notification::class => NotificationPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/notifications.php', 'notifications');

        // Learning-flow notification port: producing domains (Assessment / Q&A / Forum) reach the
        // dispatcher through this Shared contract without importing the Notifications context, so
        // wiring those flows introduces no domain<->Notifications Deptrac edge.
        $this->app->bind(LearningNotificationPort::class, LearningNotificationService::class);

        // Expiry reminders reach recipients through the same seam: Commerce sweeps what is about
        // to lapse and states the intent; deduplication, channels and delivery are handled here.
        $this->app->bind(ExpiryNotificationPort::class, ExpiryNotificationService::class);

        $this->app->singleton(AutomationEventCatalog::class);

        // Marketing audience seam fallback: CRM (registered earlier) binds its lead adapter; this
        // Null default only applies when the CRM provider is absent (e.g. an isolated test boot), so
        // a marketing send with no audience source is skipped rather than sent to an unknown address.
        $this->app->bindIf(MarketingAudiencePort::class, NullMarketingAudiencePort::class);

        // Provider selection is config-driven (fake default). Local/test never send for real.
        $this->app->bind(MailProvider::class, fn ($app) => $app->make(ProviderManager::class)->mail());
        $this->app->bind(SmsProvider::class, fn ($app) => $app->make(ProviderManager::class)->sms());
        $this->app->bind(PushProvider::class, fn ($app) => $app->make(ProviderManager::class)->push());
    }

    protected function bootDomain(): void
    {
        Event::subscribe(NotificationEventSubscriber::class);

        // Sprint 4 observability (additive; no delivery behavior changes). The observer emits
        // lifecycle events off persisted delivery state; the telemetry subscriber turns every
        // lifecycle event into a structured log line and an idempotent metric.
        NotificationDelivery::observe(NotificationDeliveryObserver::class);
        Event::subscribe(NotificationTelemetrySubscriber::class);

        $this->subscribeAutomationEngine();
        $this->registerDripSchedule();
    }

    /**
     * The marketing workflow engine. Subscribes to each catalogued domain event BY STRING CLASS NAME
     * (mirroring the outbound-webhook emitter): the listener receives the event as an opaque object
     * and hands it to the AutomationRunner, so NO domain event class is imported here and the
     * Deptrac Shared + IdentityContracts ruleset holds with zero new edges.
     */
    private function subscribeAutomationEngine(): void
    {
        /** @var AutomationEventCatalog $catalog */
        $catalog = $this->app->make(AutomationEventCatalog::class);

        foreach ($catalog->eventClasses() as $eventClass) {
            Event::listen($eventClass, function (object $event): void {
                app(AutomationRunner::class)->handle($event);
            });
        }
    }

    /**
     * Drip advance tick. Registered from THIS module's own provider (not the kernel/bootstrap), so it
     * touches no forbidden file. onOneServer()+withoutOverlapping() keep a single tick in flight; the
     * runner is idempotent and resumable so a restart never double-sends.
     */
    private function registerDripSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(new AdvanceDripCampaignsJob)
                ->everyMinute()
                ->onOneServer()
                ->withoutOverlapping();
        });
    }
}
