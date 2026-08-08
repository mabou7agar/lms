<?php

declare(strict_types=1);

namespace App\Domains\Forum\Actions;

use App\Domains\Forum\Models\ForumThread;

/**
 * Locks / unlocks a thread. Instructor-only — the controller authorizes `moderate` via
 * ForumThreadPolicy before calling this. A locked thread rejects learner replies (enforced in
 * ReplyToThreadAction); instructors may still post. `locked_at` is never mass-assignable.
 */
class LockThreadAction
{
    public function execute(ForumThread $thread, bool $locked): ForumThread
    {
        $thread->forceFill(['locked_at' => $locked ? now() : null])->save();

        return $thread;
    }
}
