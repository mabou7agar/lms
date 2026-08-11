<?php

declare(strict_types=1);

namespace App\Platform\Shared\Marketing;

use App\Platform\Shared\Marketing\Contracts\MarketingAudiencePort;

/**
 * Safe default binding for {@see MarketingAudiencePort} when no owning context has provided an
 * adapter (e.g. an isolated test boot without the CRM provider). It resolves an EMPTY segment and
 * reports NO consent, so a marketing send with no audience source is skipped rather than sent to an
 * unknown recipient. CRM overrides this binding with its concrete lead adapter.
 */
final class NullMarketingAudiencePort implements MarketingAudiencePort
{
    public function resolveSegment(int|string|null $tenantId, string $audienceType, array $filter): iterable
    {
        return [];
    }

    public function hasMarketingConsent(string $recipientType, int $recipientId): bool
    {
        return false;
    }

    public function tagRecipient(string $recipientType, int $recipientId, string $tag): void
    {
        // No-op: no owning context is available to tag against.
    }
}
