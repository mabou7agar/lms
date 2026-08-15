<?php

namespace App\Platform\Blog\Actions;

use App\Platform\Blog\Models\BlogPost;
use App\Platform\Shared\Audit\AuditLogger;

/**
 * Thin orchestrator for privileged mutations of a BlogPost. Body sanitization and version
 * snapshotting live in the model's saving/updated hooks (the single write-time points), so this
 * Action only applies the change and writes the matching audit-trail entry. No business logic
 * beyond that — keep it lean.
 */
class UpdateBlogPostAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Apply an editorial update. Sanitization + version snapshot happen in the model hooks.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(BlogPost $post, array $attributes): BlogPost
    {
        $post->fill($attributes)->save();

        $this->audit->log('blog_post.updated', $post, ['slug' => $post->slug]);

        return $post;
    }

    /** Publish the post now and record the audit entry. */
    public function publish(BlogPost $post): BlogPost
    {
        $post->publish();

        $this->audit->log('blog_post.published', $post, ['slug' => $post->slug]);

        return $post;
    }

    /** Restore a prior version (creating a fresh version) and record the audit entry. */
    public function rollback(BlogPost $post, int $version): BlogPost
    {
        $post->rollbackTo($version);

        $this->audit->log('blog_post.rolled_back', $post, ['slug' => $post->slug, 'version' => $version]);

        return $post;
    }
}
