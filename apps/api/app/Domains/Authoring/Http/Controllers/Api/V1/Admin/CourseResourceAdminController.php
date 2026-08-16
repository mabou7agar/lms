<?php

declare(strict_types=1);

namespace App\Domains\Authoring\Http\Controllers\Api\V1\Admin;

use App\Domains\Authoring\Actions\ManageCourseResourceAction;
use App\Domains\Authoring\Enums\ResourceVisibility;
use App\Domains\Authoring\Http\Resources\CourseResourceResource;
use App\Domains\Authoring\Models\CourseResource;
use App\Domains\Authoring\Models\Lesson;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Media\Contracts\MediaAssetLookupPort;
use App\Platform\Shared\Media\Contracts\MediaPickerPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Attaching files to a course, for whoever may edit that course's curriculum.
 *
 * Authorization is the SAME rule as authoring a lesson — CourseAccessPort, which resolves the one
 * definition of course ownership — because publishing a workbook to a course is editing that course.
 * A resource is always reached through its course, so an id from another instructor's course
 * resolves to a 404 rather than a 403: whether it exists is not information this endpoint gives out.
 *
 * The file itself is never uploaded here. An asset already in the media library is referenced by its
 * public id (the MediaPicker's output), which keeps one upload pipeline, one virus/processing path,
 * and one place where storage keys live.
 */
final class CourseResourceAdminController extends Controller
{
    public function __construct(
        private readonly CourseAccessPort $access,
        private readonly MediaPickerPort $picker,
        private readonly MediaAssetLookupPort $assets,
    ) {}

    /** GET /v1/authoring/courses/{course}/resources — everything attached, both scopes. */
    public function index(Request $request, string $course): JsonResponse
    {
        $courseId = $this->manageableCourseId($request, $course);

        return ApiResponse::success(CourseResourceResource::collection(
            CourseResource::query()->where('course_id', $courseId)->ordered()->get(),
        ));
    }

    /** POST /v1/authoring/courses/{course}/resources — attach a library asset to the course or a lesson. */
    public function store(Request $request, string $course, ManageCourseResourceAction $action): JsonResponse
    {
        $courseId = $this->manageableCourseId($request, $course);

        $validated = $request->validate([
            'media_id' => ['required', 'string'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'lesson_id' => ['nullable', 'string'],
            'visibility' => ['nullable', Rule::in(ResourceVisibility::values())],
            'downloadable' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        // Two separate questions, asked in order: may this actor use this asset at all (the media
        // library's own ownership rule, via the picker seam), and what is its internal key. Missing
        // and not-yours answer identically, so this cannot be used to probe which assets exist.
        $mediaPublicId = (string) $validated['media_id'];
        $actorId = $this->actor($request)->actorId();

        if (! $this->picker->isSelectable($mediaPublicId, $actorId, [], null, null)) {
            throw new NotFoundHttpException('Media asset not found.');
        }

        $assetId = $this->assets->assetIdByPublicId($mediaPublicId);

        if ($assetId === null) {
            throw new NotFoundHttpException('Media asset not found.');
        }

        $ref = $this->assets->refForAssetId($assetId);

        $resource = $action->attach([
            'course_id' => $courseId,
            'lesson_id' => $this->lessonIdInCourse($validated['lesson_id'] ?? null, $courseId),
            'media_asset_id' => $assetId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'visibility' => $validated['visibility'] ?? ResourceVisibility::Enrolled->value,
            'downloadable' => $validated['downloadable'] ?? true,
            'position' => $validated['position'] ?? $this->nextPosition($courseId),
            'created_by' => $actorId,
            // Snapshotted so a learner's list can describe the file without a media lookup per row.
            'mime_type' => $ref?->mimeType,
            'size_bytes' => is_numeric($ref?->metadata['filesize'] ?? null) ? (int) $ref->metadata['filesize'] : null,
        ]);

        return ApiResponse::created(new CourseResourceResource($resource));
    }

    /** PATCH /v1/authoring/resources/{resource} — retitle, re-scope, reorder, change who may have it. */
    public function update(Request $request, CourseResource $resource, ManageCourseResourceAction $action): JsonResponse
    {
        $courseId = $this->assertManageable($request, (int) $resource->course_id);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lesson_id' => ['sometimes', 'nullable', 'string'],
            'visibility' => ['sometimes', Rule::in(ResourceVisibility::values())],
            'downloadable' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (array_key_exists('lesson_id', $validated)) {
            $validated['lesson_id'] = $this->lessonIdInCourse($validated['lesson_id'], $courseId);
        }

        return ApiResponse::updated(new CourseResourceResource($action->revise($resource, $validated)));
    }

    /** DELETE /v1/authoring/resources/{resource} — unpublish. The library asset itself is untouched. */
    public function destroy(Request $request, CourseResource $resource, ManageCourseResourceAction $action): JsonResponse
    {
        $this->assertManageable($request, (int) $resource->course_id);

        $action->detach($resource);

        return ApiResponse::deleted();
    }

    /**
     * A lesson public id, resolved ONLY within the course being edited. A lesson from another course
     * resolves to null rather than binding, so a resource can never be attached across courses.
     */
    private function lessonIdInCourse(?string $lessonPublicId, int $courseId): ?int
    {
        if ($lessonPublicId === null || $lessonPublicId === '') {
            return null;
        }

        $id = Lesson::query()
            ->where('lessons.public_id', $lessonPublicId)
            ->whereHas('section', fn ($q) => $q->where('course_id', $courseId))
            ->value('lessons.id');

        if ($id === null) {
            throw new NotFoundHttpException('Lesson not found in this course.');
        }

        return (int) $id;
    }

    private function nextPosition(int $courseId): int
    {
        return (int) CourseResource::query()->where('course_id', $courseId)->max('position') + 1;
    }

    private function manageableCourseId(Request $request, string $coursePublicId): int
    {
        $id = $this->access->manageableCourseId($this->actor($request), $coursePublicId);

        if ($id === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        return $id;
    }

    private function assertManageable(Request $request, int $courseId): int
    {
        if (! $this->access->canManageContent($this->actor($request), $courseId)) {
            throw new NotFoundHttpException('Resource not found.');
        }

        return $courseId;
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
