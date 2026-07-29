<?php

namespace App\Platform\Notifications\Providers;

use App\Platform\Notifications\Channels\ProviderManager;
use App\Platform\Notifications\Contracts\Providers\MailProvider;
use App\Platform\Notifications\Contracts\Providers\PushProvider;
use App\Platform\Notifications\Contracts\Providers\SmsProvider;
use App\Platform\Notifications\Listeners\NotificationEventSubscriber;
use App\Platform\Notifications\Listeners\NotificationTelemetrySubscriber;
use App\Platform\Notifications\Models\Notification;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Observers\NotificationDeliveryObserver;
use App\Platform\Notifications\Policies\NotificationPolicy;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
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
    }
}
