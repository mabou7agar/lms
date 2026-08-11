<?php

declare(strict_types=1);

namespace App\Platform\Integration\Emission;

use App\Platform\Integration\Jobs\DeliverWebhookJob;
use App\Platform\Integration\Models\WebhookDelivery;
use App\Platform\Integration\Models\WebhookEndpoint;
use App\Platform\Shared\Helpers\Uuid;
use App\Platform\Shared\Tenancy\TenantContext;

/**
 * Receives a domain event (as an opaque `object`), maps it to a customer webhook via the catalog,
 * resolves the active tenant, finds that tenant's ACTIVE endpoints SUBSCRIBED to the event, creates
 * one idempotent WebhookDelivery per endpoint and queues delivery.
 *
 * Registered from IntegrationServiceProvider through Event::listen(<class-string>, ...) — the catalog
 * supplies class-strings, so no domain event class is ever imported here (Deptrac stays clean).
 *
 * Tenant safety: emission runs in the originating request/context, so TenantContext already holds the
 * acting tenant. Endpoints are filtered explicitly to that tenant (or, with no tenant resolved, to
 * platform-level NULL-org endpoints) so an org's event can NEVER fan out to another org's endpoint.
 */
final class OutboundWebhookSubscriber
{
    public function __construct(
        private readonly WebhookEventCatalog $catalog,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(object $event): void
    {
        $mapping = $this->catalog->for($event::class);

        if ($mapping === null) {
            return;
        }

        $eventName = $mapping->name;
        $tenantId = $this->tenant->id();

        $query = WebhookEndpoint::query()
            ->where('active', true)
            ->whereJsonContains('event_types', $eventName);

        // Explicit tenant fence: a resolved tenant sees ONLY its own endpoints; with no tenant
        // resolved, only platform-level (NULL-org) endpoints are eligible. An org's event can never
        // fan out to another org's endpoint.
        if ($tenantId !== null) {
            $query->where('organization_id', $tenantId->value);
        } else {
            $query->whereNull('organization_id');
        }

        $endpoints = $query->get();

        if ($endpoints->isEmpty()) {
            return;
        }

        $payload = $mapping->payloadFor($event);
        $eventId = $mapping->dedupeFor($event);

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::query()->firstOrCreate(
                [
                    'webhook_endpoint_id' => $endpoint->id,
                    'event_id' => $eventId,
                ],
                [
                    'public_id' => Uuid::v7(),
                    'organization_id' => $endpoint->organization_id,
                    'event_type' => $eventName,
                    'payload' => $payload,
                    'status' => 'pending',
                    'attempts' => 0,
                ],
            );

            // firstOrCreate returns the existing row on a redispatch — only dispatch for a NEW delivery
            // so the same event is never delivered twice to the same endpoint (idempotency).
            if ($delivery->wasRecentlyCreated) {
                DeliverWebhookJob::dispatch($delivery->id);
            }
        }
    }
}
