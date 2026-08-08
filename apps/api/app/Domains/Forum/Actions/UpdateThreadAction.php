<?php

declare(strict_types=1);

namespace App\Domains\Forum\Actions;

use App\Domains\Forum\Models\ForumThread;

/**
 * Edits a thread's title / body. IDOR is guarded upstream by ForumThreadPolicy::update (owner OR
 * course instructor OR super_admin) — the controller authorizes before calling. `body` is
 * re-sanitized on write by the model mutator.
 */
class UpdateThreadAction
{
    /** @param array{title?: string, body?: string} $data */
    public function execute(ForumThread $thread, array $data): ForumThread
    {
        $fill = [];

        if (array_key_exists('title', $data)) {
            $fill['title'] = $data['title'];
        }

        if (array_key_exists('body', $data)) {
            $fill['body'] = $data['body']; // sanitized by the model mutator
        }

        if ($fill !== []) {
            $thread->fill($fill)->save();
        }

        return $thread;
    }
}
