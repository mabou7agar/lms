<?php

declare(strict_types=1);

namespace App\Domains\Forum\Actions;

use App\Domains\Forum\Models\ForumPost;

/**
 * Edits a post's body. IDOR is guarded upstream by ForumPostPolicy::update (owner OR course
 * instructor OR super_admin). `body` is re-sanitized on write by the model mutator.
 */
class UpdatePostAction
{
    public function execute(ForumPost $post, string $body): ForumPost
    {
        $post->fill(['body' => $body])->save();

        return $post;
    }
}
