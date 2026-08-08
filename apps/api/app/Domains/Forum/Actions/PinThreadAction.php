<?php

declare(strict_types=1);

namespace App\Domains\Forum\Actions;

use App\Domains\Forum\Models\ForumThread;

/**
 * Pins / unpins a thread. Instructor-only — the controller authorizes `moderate` via
 * ForumThreadPolicy before calling this. `pinned_at` is a moderation flag, never mass-assignable.
 */
class PinThreadAction
{
    public function execute(ForumThread $thread, bool $pinned): ForumThread
    {
        $thread->forceFill(['pinned_at' => $pinned ? now() : null])->save();

        return $thread;
    }
}
