<?php

namespace App\Platform\Notifications\Enums;

enum Channel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case Sms = 'sms';
    case Push = 'push';
    case WhatsApp = 'whatsapp';
    // Registered for v1 so the delivery contract is complete, but outbound webhook transport is
    // deferred to ADR-16. This channel is always Skipped (Disabled) — it never sends.
    case Webhooks = 'webhooks';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
