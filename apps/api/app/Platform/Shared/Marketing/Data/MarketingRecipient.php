<?php

declare(strict_types=1);

namespace App\Platform\Shared\Marketing\Data;

use App\Platform\Shared\Marketing\Contracts\MarketingAudiencePort;

/**
 * Boundary-safe description of a marketing recipient, resolved by the owning context (CRM leads /
 * contacts, or Identity users) and consumed by the Notifications marketing engine WITHOUT importing
 * any of those models. Scalars only — no Eloquent crosses the boundary.
 *
 * `hasConsent` is the recipient's marketing-consent decision at resolution time (lead
 * marketing_consent / user_consents). It is a snapshot; the engine re-checks consent live at send
 * time via {@see MarketingAudiencePort::hasMarketingConsent}
 * so a withdrawal between enrollment and send still suppresses the send.
 */
final class MarketingRecipient
{
    public function __construct(
        public readonly string $recipientType,
        public readonly int $recipientId,
        public readonly string $email,
        public readonly ?string $timezone = null,
        public readonly ?string $locale = null,
        public readonly bool $hasConsent = false,
    ) {}

    /** A stable identity key for this recipient, e.g. "lead:42". */
    public function key(): string
    {
        return $this->recipientType.':'.$this->recipientId;
    }
}
