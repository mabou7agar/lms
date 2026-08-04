<?php

namespace App\Contexts\Commerce\Payments\Data;

/**
 * Normalized webhook event parsed from a provider payload.
 */
final readonly class WebhookEvent
{
    /**
     * @param  string  $type  payment.succeeded | payment.failed | refund.succeeded
     * @param  int|null  $amountMinor  For a refund event, the refunded amount in integer minor units
     *                                 (null for payment events, or when a provider cannot supply it).
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $orderReference,
        public ?string $providerReference = null,
        public ?int $amountMinor = null,
        public array $raw = [],
    ) {}
}
