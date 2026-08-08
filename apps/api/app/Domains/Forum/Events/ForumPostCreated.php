<?php

declare(strict_types=1);

namespace App\Domains\Forum\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reply was posted to a forum thread. Carries the detected @mention handles from the post body for
 * a future NotificationEventSubscriber to resolve and notify (do NOT edit that subscriber here).
 * Scalar payload only — no Eloquent — so any context may subscribe without importing a Forum model.
 *
 * NOTE: the payload carries the raw detected @handles rather than resolved user ids: the current
 * schema exposes no `username` column to resolve a handle to a user id (see UserLookupPort), so
 * resolution is intentionally deferred to the notification wiring that consumes this event.
 */
final class ForumPostCreated
{
    use Dispatchable;

    /**
     * @param  list<string>  $mentions  Raw detected @handles (see MentionParser).
     */
    public function __construct(
        public readonly int $postId,
        public readonly int $threadId,
        public readonly int $courseId,
        public readonly int $authorUserId,
        public readonly array $mentions = [],
    ) {}
}
