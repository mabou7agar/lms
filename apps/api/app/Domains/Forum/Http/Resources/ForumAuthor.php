<?php

declare(strict_types=1);

namespace App\Domains\Forum\Http\Resources;

use App\Platform\Identity\Contracts\UserLookupPort;

/**
 * Shared author-shaping helper for Forum resources. Resolves a user id to a boundary-safe
 * {name, public_id} pair via UserLookupPort — so a Forum resource never imports the User model and
 * never exposes an internal user id or any PII. Returns null when the user cannot be resolved.
 */
final class ForumAuthor
{
    /** @return array{name: string, public_id: string}|null */
    public static function for(int $userId): ?array
    {
        $ref = app(UserLookupPort::class)->refById($userId);

        if ($ref === null) {
            return null;
        }

        return [
            'name' => $ref->name,
            'public_id' => $ref->publicId,
        ];
    }
}
