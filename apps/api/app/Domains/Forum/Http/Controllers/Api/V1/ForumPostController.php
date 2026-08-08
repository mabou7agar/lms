<?php

declare(strict_types=1);

namespace App\Domains\Forum\Http\Controllers\Api\V1;

use App\Domains\Forum\Actions\DeletePostAction;
use App\Domains\Forum\Actions\ReplyToThreadAction;
use App\Domains\Forum\Actions\UpdatePostAction;
use App\Domains\Forum\Http\Resources\ForumPostResource;
use App\Domains\Forum\Models\ForumPost;
use App\Domains\Forum\Models\ForumThread;
use App\Domains\Forum\Policies\ForumThreadPolicy;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Moderation\Enums\ReportReason;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Forum posts (replies). Reply is authorized against the parent THREAD via ForumThreadPolicy
 * (enrolled / instructor / super_admin); the locked-thread and one-level-nesting invariants live in
 * ReplyToThreadAction. Edit / delete are IDOR-guarded by ForumPostPolicy (author OR instructor OR
 * super_admin). Threads bind under CourseTenantScope, so cross-tenant access 404s.
 */
class ForumPostController
{
    /** POST /forum/threads/{thread}/posts — reply to a thread (optionally to a top-level post). */
    public function store(Request $request, ForumThread $thread, ReplyToThreadAction $action): JsonResponse
    {
        $actor = $this->actor($request);

        if (! $this->threadPolicy()->createInCourse($actor, $thread->courseId())) {
            throw new AccessDeniedHttpException('You must be enrolled in this course to post.');
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'parent_id' => ['nullable', 'string'],
        ]);

        $parent = null;
        if (! empty($data['parent_id'])) {
            $parent = ForumPost::query()
                ->where('thread_id', $thread->id)
                ->where('public_id', $data['parent_id'])
                ->first();

            if ($parent === null) {
                throw new NotFoundHttpException('Parent post not found.');
            }
        }

        $post = $action->execute($actor, $thread, $data['body'], $parent);

        return ApiResponse::created(new ForumPostResource($post));
    }

    /** PATCH /forum/posts/{post} — author or instructor edits the body. */
    public function update(Request $request, ForumPost $post, UpdatePostAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorize($actor, 'update', $post);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
        ]);

        return ApiResponse::updated(new ForumPostResource($action->execute($post, $data['body'])));
    }

    /** DELETE /forum/posts/{post} — author or instructor soft-deletes. */
    public function destroy(Request $request, ForumPost $post, DeletePostAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorize($actor, 'delete', $post);

        $action->execute($post);

        return ApiResponse::deleted('Post deleted.');
    }

    /** POST /forum/posts/{post}/report — any participant flags the post for moderation. */
    public function report(Request $request, ForumPost $post): JsonResponse
    {
        $actor = $this->actor($request);

        // Only a participant of the owning thread's course may report.
        if (! $this->threadPolicy()->view($actor, $post->thread)) {
            throw new AccessDeniedHttpException('You do not have access to this forum.');
        }

        $data = $request->validate([
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $post->report($actor->actorId(), ReportReason::from($data['reason']), $data['note'] ?? null);

        return ApiResponse::success(null, 'Reported.', 201);
    }

    private function authorize(Actor $actor, string $ability, ForumPost $post): void
    {
        if (! Gate::forUser($actor)->allows($ability, $post)) {
            throw new AccessDeniedHttpException('This action is unauthorized.');
        }
    }

    private function threadPolicy(): ForumThreadPolicy
    {
        return app(ForumThreadPolicy::class);
    }

    private function actor(Request $request): Actor
    {
        $actor = $request->user();

        if (! $actor instanceof Actor) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        return $actor;
    }
}
