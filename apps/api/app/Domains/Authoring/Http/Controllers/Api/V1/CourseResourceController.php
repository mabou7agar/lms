<?php

declare(strict_types=1);

namespace App\Domains\Authoring\Http\Controllers\Api\V1;

use App\Domains\Authoring\Events\CourseResourceDownloaded;
use App\Domains\Authoring\Http\Resources\CourseResourceResource;
use App\Domains\Authoring\Models\CourseResource;
use App\Domains\Authoring\Models\Lesson;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Catalog\Contracts\CourseLookupPort;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use App\Platform\Shared\Media\Contracts\MediaAssetLookupPort;
use App\Platform\Shared\Media\Contracts\PlaybackPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The learner's side of course files: what is attached, and a link to actually take one.
 *
 * ENTITLEMENT IS CHECKED AT DOWNLOAD TIME, not baked into a durable link. That ordering is the whole
 * security design: the URL handed back is short-lived and signed by the media platform, so a link
 * shared afterwards dies on its own, and a company seat that expires between two clicks stops
 * working on the second. The alternative — a long-lived URL minted once — would outlive every
 * entitlement rule the platform has.
 *
 * A raw storage key or provider id never appears in any response here; the signed URL is all that
 * crosses the wire.
 */
final class CourseResourceController extends Controller
{
    public function __construct(
        private readonly CourseAccessPort $access,
        private readonly CourseEnrollmentPort $enrollment,
        private readonly CourseLookupPort $courses,
    ) {}

    /** GET /v1/courses/{course}/resources — course-level files, or all of them with ?scope=all. */
    public function index(Request $request, string $course): JsonResponse
    {
        $actor = $this->actor($request);
        $courseId = $this->resolveCourseId($course);
        $entitled = $this->isEntitled($actor, $courseId);

        $validated = $request->validate([
            'scope' => ['nullable', 'in:course,all'],
        ]);

        $query = CourseResource::query()->where('course_id', $courseId)->ordered();

        if (($validated['scope'] ?? 'course') === 'course') {
            $query->courseLevel();
        }

        // Someone who has not bought the course sees only what the course chose to give away. The
        // list is filtered rather than refused, so a course page can advertise its sample material.
        if (! $entitled) {
            $query->previewable();
        }

        // `entitled` travels beside the list because the UI needs both facts at once: what exists,
        // and whether this viewer may take any of it. A resource collection's additional() keys do
        // not survive the response envelope, so the shape is built explicitly.
        return ApiResponse::success([
            'entitled' => $entitled,
            'items' => CourseResourceResource::collection($query->get())->resolve(),
        ]);
    }

    /** GET /v1/lessons/{lesson}/resources — the files attached to one lesson, for the player. */
    public function forLesson(Request $request, string $lesson): JsonResponse
    {
        $actor = $this->actor($request);

        $lessonId = Lesson::query()->where('public_id', $lesson)->value('id');

        if ($lessonId === null) {
            throw new NotFoundHttpException('Lesson not found.');
        }

        $resources = CourseResource::query()->where('lesson_id', $lessonId)->ordered()->get();

        if ($resources->isEmpty()) {
            return ApiResponse::success(['entitled' => false, 'items' => []]);
        }

        $entitled = $this->isEntitled($actor, (int) $resources->first()->course_id);
        $visible = $entitled ? $resources : $resources->filter(fn (CourseResource $r): bool => $r->isPreview());

        return ApiResponse::success([
            'entitled' => $entitled,
            'items' => CourseResourceResource::collection($visible->values())->resolve(),
        ]);
    }

    /**
     * POST /v1/resources/{resource}/download — mint a short-lived signed URL.
     *
     * The entitlement check happens here, on every request, which is what makes an expired company
     * seat stop working the moment it expires rather than whenever a cached link happens to run out.
     */
    public function download(
        Request $request,
        CourseResource $resource,
        PlaybackPort $playback,
        MediaAssetLookupPort $assets,
    ): JsonResponse {
        $actor = $this->actor($request);
        $courseId = (int) $resource->course_id;

        if (! $resource->downloadable) {
            throw new AccessDeniedHttpException('This file is not available for download.');
        }

        if ($resource->visibility->requiresEntitlement() && ! $this->isEntitled($actor, $courseId)) {
            throw new AccessDeniedHttpException('You do not have access to this course.');
        }

        $ref = $assets->refForAssetId((int) $resource->getAttribute('media_asset_id'));

        if ($ref === null) {
            throw new NotFoundHttpException('File not found.');
        }

        $token = $playback->issue($ref, (int) config('learning.playback.ttl_seconds', 600));

        // Published after the URL is issued, and nothing waits on it: a reporting concern must never
        // stand between a learner and a file they paid for.
        CourseResourceDownloaded::dispatch(
            (int) $resource->id,
            $courseId,
            $resource->lesson_id === null ? null : (int) $resource->lesson_id,
            $actor->actorId(),
        );

        return ApiResponse::success([
            'url' => $token->url,
            'expires_at' => $token->expiresAt->toIso8601String(),
            'title' => $resource->title,
        ]);
    }

    /** Entitled = course staff, or a learner whose access has not lapsed. */
    private function isEntitled(Actor $actor, int $courseId): bool
    {
        return $this->access->canManageContent($actor, $courseId)
            || $this->enrollment->hasCourseAccess($courseId, $actor->actorId());
    }

    /**
     * Resolve the course public id. Published-only, via the Shared lookup port, so this controller
     * never imports Catalog's model — an unpublished course's files are not a public listing.
     */
    private function resolveCourseId(string $publicId): int
    {
        $course = $this->courses->publishedCourseByPublicId($publicId);

        if ($course === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        return (int) $course['id'];
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
