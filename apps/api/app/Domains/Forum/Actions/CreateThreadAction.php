<?php

declare(strict_types=1);

namespace App\Domains\Forum\Actions;

use App\Domains\Forum\Events\ForumThreadCreated;
use App\Domains\Forum\Models\ForumThread;
use App\Domains\Forum\Support\CourseTenantVisibility;
use App\Domains\Forum\Support\MentionParser;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Opens a new discussion thread in a course.
 *
 * The controller has already resolved a TENANT-SCOPED course id and authorized participation
 * (enrolled or course instructor) via ForumThreadPolicy. This action owns the data invariants:
 *   - `body` is sanitized on write by the model (single HtmlSanitizer point);
 *   - `organization_id` is stamped SERVER-SIDE from the course row (never from client input);
 *   - a defensive tenant-visibility re-check rejects a course outside the active tenant so a thread
 *     can never be stamped onto another organization's private course.
 */
class CreateThreadAction extends BaseAction
{
    public function execute(Actor $actor, int $courseId, string $title, string $body): ForumThread
    {
        // Server-side tenant stamp: read the owning course's organization straight from the row
        // (string table reference — no Course model import).
        $organizationId = DB::table('courses')->where('id', $courseId)->value('organization_id');

        // Defensive write-path tenancy guard (read isolation is enforced by CourseTenantScope).
        if (! CourseTenantVisibility::visible($organizationId)) {
            throw new NotFoundHttpException('Course not found.');
        }

        return $this->transaction(function () use ($actor, $courseId, $organizationId, $title, $body): ForumThread {
            $thread = new ForumThread;
            $thread->fill([
                'course_id' => $courseId,
                'title' => $title,
                'body' => $body, // sanitized by the model mutator
            ]);
            $thread->forceFill([
                'user_id' => $actor->actorId(),
                'organization_id' => $organizationId !== null ? (int) $organizationId : null,
                'last_post_at' => now(),
            ]);
            $thread->save();

            ForumThreadCreated::dispatch(
                $thread->id,
                $courseId,
                $actor->actorId(),
                MentionParser::handles($body),
            );

            return $thread;
        });
    }
}
