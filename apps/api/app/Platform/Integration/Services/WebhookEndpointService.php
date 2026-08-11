<?php

declare(strict_types=1);

namespace App\Platform\Integration\Services;

use App\Platform\Integration\Jobs\DeliverWebhookJob;
use App\Platform\Integration\Models\WebhookDelivery;
use App\Platform\Integration\Models\WebhookEndpoint;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Helpers\Uuid;

/**
 * All state changes for outbound webhook endpoints + deliveries. Secrets are generated here (never by
 * a client), returned exactly once to the caller (create/rotate) and otherwise never leave the server.
 * Privileged actions are audited.
 */
final class WebhookEndpointService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Create an endpoint. The plaintext secret is set on the returned model (revealed ONCE by the
     * controller); it is $hidden so it is never serialized again. organization_id is stamped from the
     * resolved tenant by the BelongsToTenant trait — never from client input.
     *
     * @param  array{name: string, description?: string|null, url: string, event_types: array<int, string>}  $data
     */
    public function create(array $data, ?int $createdBy): WebhookEndpoint
    {
        $endpoint = new WebhookEndpoint;
        $endpoint->forceFill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'url' => $data['url'],
            'secret' => $this->generateSecret(),
            'event_types' => array_values($data['event_types']),
            'active' => true,
            'consecutive_failures' => 0,
            'created_by' => $createdBy,
        ]);
        $endpoint->save();

        $this->audit->log('webhook_endpoint.created', $endpoint, ['events' => $endpoint->event_types]);

        return $endpoint;
    }

    /** Rotate the signing secret. Returns the NEW plaintext secret (shown once). */
    public function rotateSecret(WebhookEndpoint $endpoint): string
    {
        $secret = $this->generateSecret();
        $endpoint->forceFill(['secret' => $secret])->save();

        $this->audit->log('webhook_endpoint.secret_rotated', $endpoint);

        return $secret;
    }

    /**
     * @param  array{name?: string, description?: string|null, url?: string, event_types?: array<int, string>}  $data
     */
    public function update(WebhookEndpoint $endpoint, array $data): WebhookEndpoint
    {
        $attributes = [];

        foreach (['name', 'description', 'url'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('event_types', $data)) {
            $attributes['event_types'] = array_values($data['event_types']);
        }

        if ($attributes !== []) {
            $endpoint->forceFill($attributes)->save();
            $this->audit->log('webhook_endpoint.updated', $endpoint, ['fields' => array_keys($attributes)]);
        }

        return $endpoint;
    }

    /** Enable/disable an endpoint. Enabling clears the failure streak + disabled_at. */
    public function setActive(WebhookEndpoint $endpoint, bool $active): WebhookEndpoint
    {
        $endpoint->forceFill([
            'active' => $active,
            'disabled_at' => $active ? null : now(),
            'consecutive_failures' => $active ? 0 : $endpoint->consecutive_failures,
        ])->save();

        $this->audit->log($active ? 'webhook_endpoint.enabled' : 'webhook_endpoint.disabled', $endpoint);

        return $endpoint;
    }

    /**
     * Replay a delivery: mint a NEW delivery row (distinct event_id so the idempotency unique key is
     * preserved) carrying the original payload, and queue it. Returns the new delivery.
     */
    public function replay(WebhookDelivery $delivery): WebhookDelivery
    {
        $replay = new WebhookDelivery;
        $replay->forceFill([
            'public_id' => Uuid::v7(),
            'webhook_endpoint_id' => $delivery->webhook_endpoint_id,
            'organization_id' => $delivery->organization_id,
            'event_type' => $delivery->event_type,
            'event_id' => $delivery->event_id.':replay:'.substr(Uuid::v4(), 0, 12),
            'payload' => $delivery->payload,
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $replay->save();

        $this->audit->log('webhook_delivery.replayed', $replay, ['source' => $delivery->public_id]);

        DeliverWebhookJob::dispatch($replay->id);

        return $replay;
    }

    private function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
