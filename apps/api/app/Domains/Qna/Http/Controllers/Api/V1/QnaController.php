<?php

declare(strict_types=1);

namespace App\Domains\Qna\Http\Controllers\Api\V1;

use App\Domains\Qna\Http\Resources\QuestionResource;
use App\Domains\Qna\Support\CourseLocator;
use App\Domains\Qna\Support\ResolvedCourse;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Identity\Contracts\Data\UserRef;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared plumbing for the Q&A HTTP surface: actor resolution, tenant-safe course lookup, the
 * course-participation gate for course-scoped reads, and boundary-safe author resolution. Kept out
 * of the individual controllers so those stay thin and every entry point applies the same gates.
 */
abstract class QnaController
{
    public function __construct(
        protected readonly CourseLocator $courseLocator,
        protected readonly CourseAccessPort $access,
        protected readonly CourseEnrollmentPort $enrollment,
        protected readonly UserLookupPort $users,
    ) {}

    /** The authenticated principal, or a 403 if somehow unauthenticated. */
    protected function actor(Request $request): Actor
    {
        $actor = $request->user();

        if (! $actor instanceof Actor) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        return $actor;
    }

    /** Resolve a tenant-visible course by public_id, or a clean 404 (never leaks cross-tenant existence). */
    protected function resolveCourseOr404(string $coursePublicId): ResolvedCourse
    {
        $course = $this->courseLocator->locate($coursePublicId);

        if ($course === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        return $course;
    }

    /**
     * Course-scoped reads (the questions listing) have no model instance to policy-check, so the
     * participation gate is applied here: a course instructor/super_admin or an enrolled/entitled
     * learner. Anyone else gets a 403 — the same rule the policies enforce for model-bound reads.
     */
    protected function assertParticipation(Actor $actor, int $courseId): void
    {
        $participates = $this->access->canManageContent($actor, $courseId)
            || $this->enrollment->hasCourseAccess($courseId, $actor->actorId());

        if (! $participates) {
            throw new AccessDeniedHttpException('You do not have access to this course.');
        }
    }

    /**
     * Resolve a lesson public_id to its internal id, but ONLY when the lesson genuinely belongs to
     * the given course (joined through sections). Reads the lessons/sections tables by name — no
     * Authoring model import — so a caller can never anchor a question to another course's lesson.
     * Returns null for "not provided", "no such lesson", or "not in this course".
     */
    protected function resolveLessonIdInCourse(?string $lessonPublicId, int $courseId): ?int
    {
        if ($lessonPublicId === null || $lessonPublicId === '') {
            return null;
        }

        $id = DB::table('lessons')
            ->join('sections', 'lessons.section_id', '=', 'sections.id')
            ->where('lessons.public_id', $lessonPublicId)
            ->whereNull('lessons.deleted_at')
            ->where('sections.course_id', $courseId)
            ->value('lessons.id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Resolve many author ids to boundary-safe UserRefs in a single lookup (no N+1, no PII leak).
     *
     * @param  iterable<int|string>  $userIds
     * @return array<int, UserRef>
     */
    protected function authorsFor(iterable $userIds): array
    {
        $ids = [];

        foreach ($userIds as $id) {
            $ids[(int) $id] = (int) $id;
        }

        return $this->users->refsByIds(array_values($ids));
    }

    /**
     * Render a question paginator into the standard paginated envelope, injecting each question's
     * author. Mirrors ApiResponse::paginated but threads the author map the summary resource needs.
     *
     * @param  LengthAwarePaginator<int, \App\Domains\Qna\Models\CourseQuestion>  $paginator
     * @param  array<int, UserRef>  $authors
     */
    protected function paginatedQuestions(LengthAwarePaginator $paginator, array $authors): JsonResponse
    {
        $data = $paginator->getCollection()
            ->map(fn ($question) => (new QuestionResource(
                $question,
                $authors[(int) $question->user_id] ?? null,
            ))->resolve())
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }
}
