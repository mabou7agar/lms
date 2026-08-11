<?php

declare(strict_types=1);

namespace App\Platform\Shared\Marketing\Contracts;

use App\Platform\Shared\Marketing\Data\MarketingRecipient;

/**
 * The single cross-context seam for the marketing engine. DECLARED in Shared and IMPLEMENTED by the
 * owning context (CRM for leads/contacts). The Notifications marketing engine reaches audience data
 * and consent ONLY through this port, so it never imports a CRM model and no Deptrac edge is created.
 *
 * All arguments/returns are scalars or the Shared {@see MarketingRecipient} DTO — boundary-safe.
 */
interface MarketingAudiencePort
{
    /**
     * Resolve the recipients of a segment for a tenant. `$audienceType` selects the source
     * ("lead"/"contact"); `$filter` is an allow-listed attribute map (e.g. ['status' => 'new']).
     *
     * @param  array<string, scalar>  $filter
     * @return iterable<int, MarketingRecipient>
     */
    public function resolveSegment(int|string|null $tenantId, string $audienceType, array $filter): iterable;

    /** Live marketing-consent re-check for one recipient (lead marketing_consent / user_consents). */
    public function hasMarketingConsent(string $recipientType, int $recipientId): bool;

    /** Apply a marketing tag/label to a recipient (the "tag a lead" automation action). */
    public function tagRecipient(string $recipientType, int $recipientId, string $tag): void;
}
