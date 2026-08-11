<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Support;

use Illuminate\Support\Facades\URL;

/**
 * Builds the public, signed, single-purpose unsubscribe link embedded in a marketing message.
 *
 * The signature covers the WHOLE URL — including the email, category and tenant — so a link cannot be
 * edited to unsubscribe a different recipient or a different (e.g. transactional) category and still
 * verify. No auth is required to follow it; the signature IS the authorization.
 */
final class UnsubscribeLinkGenerator
{
    public const ROUTE_NAME = 'marketing.unsubscribe';

    public function for(string $email, string $category = 'marketing', int|string|null $organizationId = null): string
    {
        return URL::signedRoute(self::ROUTE_NAME, [
            'email' => $email,
            'category' => $category,
            'org' => $organizationId,
        ]);
    }
}
