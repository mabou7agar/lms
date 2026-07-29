<?php

namespace App\Platform\Notifications\Support;

use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Shared\Tenancy\Contracts\CurrentTenantProvider;
use Illuminate\Support\Facades\Context;

/**
 * Builds the structured (metadata-only) log context for one delivery. It carries identifiers and
 * outcome — never the notification's title, body, or data payload — so nothing sensitive and no
 * secret is ever logged. Missing/optional fields are simply omitted.
 */
class NotificationLogContext
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function for(NotificationDelivery $delivery, array $extra = []): array
    {
        $delivery->loadMissing('notification');
        $notification = $delivery->notification;

        $context = array_filter([
            'notification_id' => $delivery->notification_id,
            'delivery_id' => $delivery->getKey(),
            'dedup_key' => $notification?->dedup_key,
            'channel' => $delivery->channel->value,
            'provider' => $delivery->provider,
            'delivery_status' => $delivery->status->value,
            'attempts' => (int) ($delivery->attempts ?? 0),
            'latency_ms' => self::latencyMs($delivery),
            'correlation_id' => self::correlationId(),
            'user_id' => $notification?->user_id,
            'tenant_id' => self::tenantId(),
        ], static fn ($value): bool => $value !== null);

        return array_merge($context, $extra);
    }

    /** Milliseconds from the notification being created (queued) to its terminal moment, if known. */
    private static function latencyMs(NotificationDelivery $delivery): ?int
    {
        $start = $delivery->notification?->created_at;
        $end = $delivery->sent_at ?? $delivery->dead_at;

        if ($start === null || $end === null) {
            return null;
        }

        return (int) round($start->diffInMilliseconds($end, true));
    }

    private static function correlationId(): ?string
    {
        $id = Context::get('correlation_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** Best-effort tenant, when a tenant is resolvable in the current request/job context. */
    private static function tenantId(): int|string|null
    {
        if (! app()->bound(CurrentTenantProvider::class)) {
            return null;
        }

        return app(CurrentTenantProvider::class)->currentTenant()?->value;
    }
}
