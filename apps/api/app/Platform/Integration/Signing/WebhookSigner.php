<?php

declare(strict_types=1);

namespace App\Platform\Integration\Signing;

/**
 * Produces the outbound HMAC signature + headers for a webhook delivery, mirroring the inbound
 * provider verifiers' crypto (constant HMAC-SHA over a canonical string), inverted for SIGNING.
 *
 * The signature covers "{timestamp}.{body}" so a receiver can reject stale/replayed requests by
 * checking the timestamp skew before recomputing HMAC-SHA256(secret, "{timestamp}.{body}").
 *
 * Header contract (what receivers verify against):
 *   X-Webhook-Id        — unique delivery id (public_id), for receiver-side idempotency.
 *   X-Webhook-Event     — the webhook event name (e.g. "course.completed").
 *   X-Webhook-Timestamp — unix seconds used in the signed string.
 *   X-Webhook-Signature — "sha256=" . hex HMAC-SHA256(secret, "{timestamp}.{body}").
 */
final class WebhookSigner
{
    public const SIGNATURE_HEADER = 'X-Webhook-Signature';

    public const TIMESTAMP_HEADER = 'X-Webhook-Timestamp';

    public const ID_HEADER = 'X-Webhook-Id';

    public const EVENT_HEADER = 'X-Webhook-Event';

    /** The "sha256=<hex>" signature over "{timestamp}.{body}". */
    public function sign(string $secret, string $body, int $timestamp): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * All signed headers for a delivery.
     *
     * @return array<string, string>
     */
    public function headers(string $secret, string $body, string $deliveryId, string $eventName, int $timestamp): array
    {
        return [
            self::ID_HEADER => $deliveryId,
            self::EVENT_HEADER => $eventName,
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::SIGNATURE_HEADER => $this->sign($secret, $body, $timestamp),
        ];
    }
}
