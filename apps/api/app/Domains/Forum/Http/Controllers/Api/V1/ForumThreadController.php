<?php

declare(strict_types=1);

namespace App\Domains\Forum\Http\Controllers\Api\V1;

use App\Domains\Forum\Actions\CreateThreadAction;
use App\Domains\Forum\Actions\DeleteThreadAction;
use App\Domains\Forum\Actions\LockThreadAction;
use App\Domains\Forum\Actions\MarkSolvedAction;
use App\Domains\Forum\Actions\PinThreadAction;
use App\Domains\Forum\Actions\UpdateThreadAction;
use App\Domains\Forum\Http\Resources\ForumPostResource;
use App\Domains\Forum\Http\Resources\ForumThreadResource;
use App\Domains\Forum\Models\ForumPost;
use App\Domains\Forum\Models\ForumThread;
use App\Domains\Forum\Policies\ForumThreadPolicy;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Moderation\Enums\ReportReason;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Course discussion threads. Course-scoped routes resolve the course PUBLIC id through
 * CurriculumReadPort (this domain never imports the Course model); the resolution is tenant-scoped,
 * so a course outside the active tenant 404s. Thread routes bind ForumThread by public_id under the
 * CourseTenantScope, so a cross-tenant thread is likewise 404. Participation (enrolled / instructor /
 * super_admin) and moderation are enforced through ForumThreadPolicy.
 */
class ForumThreadController
{
    public function __construct(private readonly CurriculumReadPort $curriculum) {}

    /** GET /courses/{course}/forum/threads — pinned first, then most-recent activity; optional ?q= search. */
    public function index(Request $request, string $course): JsonResponse
    {
        $actor = $this->actor($request);
        $courseId = $this->resolveCourseId($course);

        if (! $this->policy()->viewCourse($actor, $courseId)) {
            throw new AccessDeniedHttpException('You do not have access to this forum.');
        }

        $query = ForumThread::query()->where('course_id', $courseId);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where('title', 'like', '%'.$search.'%');
        }

        $threads = $query
            ->orderByRaw('pinned_at IS NULL') // pinned (non-null) first
            ->orderByDesc('last_post_at')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 20));

        return ApiResponse::paginated($threads, ForumThreadResource::class);
    }

    /** POST /courses/{course}/forum/threads — enrolled learners (or instructors) start a thread. */
    public function store(Request $request, string $course, CreateThreadAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        $courseId = $this->resolveCourseId($course);

        if (! $this->policy()->createInCourse($actor, $courseId)) {
            throw new AccessDeniedHttpException('You must be enrolled in this course to post.');
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $thread = $action->execute($actor, $courseId, $data['title'], $data['body']);

        return ApiResponse::created(new ForumThreadResource($thread));
    }

    /** GET /forum/threads/{thread} — the thread plus a page of top-level posts (with one-level replies). */
    public function show(Request $request, ForumThread $thread): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorize($actor, 'view', $thread);

        $thread->load('solvedPost');

        $posts = ForumPost::query()
            ->where('thread_id', $thread->id)
            ->whereNull('parent_post_id')
            ->with(['replies' => fn ($q) => $q->orderBy('id')])
            ->orderBy('id')
            ->paginate((int) $request->integer('per_page', 20));

        return ApiResponse::success(
            [
                'thread' => (new ForumThreadResource($thread))->resolve($request),
                'posts' => ForumPostResource::collection($posts->getCollection())->resolve($request),
            ],
            null,
            200,
            [
                'posts' => [
                    'current_page' => $posts->currentPage(),
                    'per_page' => $posts->perPage(),
                    'total' => $posts->total(),
                    'last_page' => $posts->lastPage(),
                ],
            ],
        );
    }

    /** PATCH /forum/threads/{thread} — author or instructor edits title/body. */
    public function update(Request $request, ForumThread $thread, UpdateThreadAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorize($actor, 'update', $thread);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'body' => ['sometimes', 'string', 'max:20000'],
        ]);

        return ApiResponse::updated(new ForumThreadResource($action->execute($thread, $data)));
    }

    /** DELETE /forum/threads/{thread} — author or instructor soft-deletes. */
    public function destroy(Request $request, ForumThread $thread, DeleteThreadAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorize($actor, 'delete', $thread);

        $action->execute($thread);

        return ApiResponse::deleted('Thread deleted.');
    }

    /** POST /forum/threads/{thread}/pin — instructor moderation. */
    public function pin(Request $request, ForumThread $thread, PinThreadAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorize($actor, 'moderate', $thread);

        $pinned = $request->boolean('pinned', true);

        return ApiResponse::updated(new ForumThreadResource($action->execute($thread, $pinned)));
    }

    /** POST /forum/threads/{thread}/lock — instructor moderation. */
    public function lock(Request $request, ForumThread $thread, LockThreadAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorize($actor, 'moderate', $thread);

        $locked = $request->boolean('locked', true);

        return ApiResponse::updated(new ForumThreadResource($action->execute($thread, $locked)));
    }

    /** POST /forum/threads/{thread}/solve — instructor accepts an answer (or clears it). */
    public function solve(Request $request, ForumThread $thread, MarkSolvedAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorize($actor, 'moderate', $thread);

        $data = $request->validate([
            'post_id' => ['nullable', 'string'],
        ]);

        $post = null;
        if (! empty($data['post_id'])) {
            $post = ForumPost::query()
                ->where('thread_id', $thread->id)
                ->where('public_id', $data['post_id'])
                ->first();

            if ($post === null) {
                throw new NotFoundHttpException('Post not found.');
            }
        }

        return ApiResponse::updated(new ForumThreadResource($action->execute($thread, $post)));
    }

    /** POST /forum/threads/{thread}/report — any participant flags the thread for moderation. */
    public function report(Request $request, ForumThread $thread): JsonResponse
    {
        $actor = $this->actor($request);

        // Only a participant may report (mirrors view access).
        if (! $this->policy()->view($actor, $thread)) {
            throw new AccessDeniedHttpException('You do not have access to this forum.');
        }

        $data = $request->validate([
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $thread->report($actor->actorId(), ReportReason::from($data['reason']), $data['note'] ?? null);

        return ApiResponse::success(null, 'Reported.', 201);
    }

    /** Gate-routed model ability check (applies ForumThreadPolicy::before + the ability). */
    private function authorize(Actor $actor, string $ability, ForumThread $thread): void
    {
        if (! Gate::forUser($actor)->allows($ability, $thread)) {
            throw new AccessDeniedHttpException('This action is unauthorized.');
        }
    }

    private function resolveCourseId(string $coursePublicId): int
    {
        $course = $this->curriculum->findCourseByPublicId($coursePublicId);

        if ($course === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        return $course->id;
    }

    private function policy(): ForumThreadPolicy
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
