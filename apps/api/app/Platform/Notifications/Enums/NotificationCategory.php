<?php

namespace App\Platform\Notifications\Enums;

enum NotificationCategory: string
{
    case Account = 'account';
    case Learning = 'learning';
    case Commerce = 'commerce';
    case Certification = 'certification';
    case Live = 'live';
    case Crm = 'crm';
    case System = 'system';
    // Bulk outbound marketing (campaigns/drip). The ONLY category subject to consent + suppression +
    // quiet-hours deferral. Every other category is transactional/critical and bypasses all three.
    case Marketing = 'marketing';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * Marketing is the sole suppressible category: it requires consent, honours the unsubscribe
     * suppression list, and is deferred out of quiet hours. All transactional categories send
     * regardless — this is the transactional-bypass rule expressed in one place.
     */
    public function isMarketing(): bool
    {
        return $this === self::Marketing;
    }

    /** True for every category that MUST NOT be suppressed or quiet-hours-deferred. */
    public function isTransactional(): bool
    {
        return ! $this->isMarketing();
    }
}
