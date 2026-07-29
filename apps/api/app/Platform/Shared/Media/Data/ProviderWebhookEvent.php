<?php

namespace App\Platform\Shared\Media\Data;

/**
 * P2/W04 - A verified, normalised provider webhook. $id is the provider's unique event id, used
 * for idempotent processing; $providerRef ties it to a local media asset; $status is already
 * mapped onto our lifecycle via the enclosed ProviderAssetStatus.
 */
final readonly class ProviderWebhookEvent
{
    public function __construct(
        public string $id,
        public string $type,
        public string $providerRef,
        public ProviderAssetStatus $status,
    ) {}
}
