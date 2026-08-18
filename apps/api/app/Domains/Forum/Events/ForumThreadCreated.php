<?php

declare(strict_types=1);

namespace App\Domains\Forum\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new forum thread was opened. Carries the detected @mention handles from the thread body for a
 * future NotificationEventSubscriber to resolve and notify. Scalar payload only — no Eloquent —
 * so any context may subscribe without importing a Forum model.
 */
final class ForumThreadCreated
{
    use Dispatchable;

    /**
     * @param  list<string>  $mentions  Raw detected @handles (see MentionParser); resolution to user
     *                                  ids is deferred to the future notification wiring.
     */
    public function __construct(
        public readonly int $threadId,
        public readonly int $courseId,
        public readonly int $authorUserId,
        public readonly array $mentions = [],
    ) {}
}
