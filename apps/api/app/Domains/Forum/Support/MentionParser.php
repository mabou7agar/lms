<?php

declare(strict_types=1);

namespace App\Domains\Forum\Support;

/**
 * Detects @mention handles inside a body of user-authored text. This is DETECTION ONLY — it stores
 * nothing binding and resolves nothing: the current schema has no `username` column (email is the
 * credential, public_id the external key — see UserLookupPort), so there is no reliable handle->user
 * mapping to resolve against here. The detected handles ride on ForumThreadCreated / ForumPostCreated
 * for a future NotificationEventSubscriber to resolve and fan out; that wiring is out of scope now.
 */
final class MentionParser
{
    /**
     * Extract unique @handles (2–50 chars of word / dot / hyphen), order-preserved.
     *
     * @return list<string>
     */
    public static function handles(string $body): array
    {
        if (preg_match_all('/(?<![\w@])@([A-Za-z0-9_.\-]{2,50})/u', $body, $matches) === false) {
            return [];
        }

        /** @var list<string> $handles */
        $handles = array_values(array_unique($matches[1] ?? []));

        return $handles;
    }
}
