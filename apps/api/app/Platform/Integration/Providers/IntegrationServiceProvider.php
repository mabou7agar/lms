<?php

declare(strict_types=1);

namespace App\Platform\Integration\Providers;

use App\Platform\Integration\Emission\OutboundWebhookSubscriber;
use App\Platform\Integration\Emission\WebhookEventCatalog;
use App\Platform\Integration\Security\WebhookUrlGuard;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Wires the OUTBOUND webhook platform: loads the Integration migrations + management routes (via
 * BaseDomainServiceProvider), binds the URL guard/catalog, and subscribes the outbound emitter to the
 * selected domain events.
 *
 * DEPTRAC: the subscription is done via Event::listen(<class-string>, ...) using the catalog's STRING
 * class names — no domain event class is imported here or anywhere in the Integration layer, so the
 * Shared + IdentityContracts ruleset holds with zero new edges.
 */
class IntegrationServiceProvider extends BaseDomainServiceProvider
{
    /** @var list<string> */
    protected array $routeFiles = [
        'routes/integration.php',
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/integration.php', 'integration');

        $this->app->singleton(WebhookEventCatalog::class);

        $this->app->singleton(
            WebhookUrlGuard::class,
            fn (): WebhookUrlGuard => new WebhookUrlGuard((bool) config('integration.security.require_https', true)),
        );
    }

    protected function bootDomain(): void
    {
        /** @var WebhookEventCatalog $catalog */
        $catalog = $this->app->make(WebhookEventCatalog::class);

        // Subscribe to each selected domain event BY STRING CLASS NAME. When the event fires, the
        // listener receives the event instance and hands it to the outbound emitter as an opaque object.
        foreach ($catalog->eventClasses() as $eventClass) {
            Event::listen($eventClass, function (object $event): void {
                app(OutboundWebhookSubscriber::class)->handle($event);
            });
        }
    }
}
