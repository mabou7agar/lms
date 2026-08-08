<?php

declare(strict_types=1);

namespace App\Domains\Forum\Actions;

use App\Domains\Forum\Models\ForumThread;

/**
 * Soft-deletes a thread. IDOR is guarded upstream by ForumThreadPolicy::delete (owner OR course
 * instructor OR super_admin) — the controller authorizes before calling.
 */
class DeleteThreadAction
{
    public function execute(ForumThread $thread): void
    {
        $thread->delete();
    }
}
