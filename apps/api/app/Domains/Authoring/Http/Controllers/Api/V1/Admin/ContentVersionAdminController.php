<?php

namespace App\Domains\Authoring\Http\Controllers\Api\V1\Admin;

use App\Domains\Authoring\Http\Requests\CloneVersionRequest;
use App\Domains\Authoring\Http\Requests\CreateSnapshotRequest;
use App\Domains\Authoring\Http\Requests\ForkVersionRequest;
use App\Domains\Authoring\Http\Resources\ContentVersionResource;
use App\Domains\Authoring\Models\ContentVersion;
use App\Domains\Authoring\Services\ContentVersioningService;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * P2/W03 - Content versioning API. Course-scoped routes take the course PUBLIC id as a plain string
 * and resolve it through CourseAccessPort (this domain never imports the Course model). Version
 * routes bind a ContentVersion by public_id and authorize through ContentVersionPolicy.
 */
class ContentVersionAdminController
{
    public function __construct(
        private readonly CourseAccessPort $courses,
        private readonly ContentVersioningService $versioning,
    ) {}

    public function index(Request $request, string $course): JsonResponse
    {
        $courseId = $this->manageableCourse($request, $course);

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);

        $versions = ContentVersion::query()
            ->forCourse($courseId)
            ->with('sourceVersion')
            ->orderByDesc('version_number')
            ->paginate($perPage);

        return ApiResponse::paginated($versions, ContentVersionResource::class);
    }

    public function store(CreateSnapshotRequest $request, string $course): JsonResponse
    {
        $courseId = $this->manageableCourse($request, $course);
        $actor = $this->actor($request);

        $version = $this->versioning->createSnapshot(
            $courseId,
            $actor->actorId(),
            $request->validated('label'),
            (bool) $request->boolean('force'),
        );

        return ApiResponse::created(new ContentVersionResource($version->load('sourceVersion')));
    }

    public function show(ContentVersion $version): JsonResponse
    {
        Gate::authorize('view', $version);

        return ApiResponse::success(new ContentVersionResource($version->load('sourceVersion')));
    }

    public function restore(Request $request, ContentVersion $version): JsonResponse
    {
        Gate::authorize('restore', $version);

        $safety = $this->versioning->restoreDraft($version, $this->actor($request)->actorId());

        return ApiResponse::success(
            [
                'restored' => new ContentVersionResource($version->load('sourceVersion')),
                'safety_snapshot' => new ContentVersionResource($safety->load('sourceVersion')),
            ],
            'Draft restored. A safety snapshot of the previous draft was created first.',
        );
    }

    public function rollback(Request $request, ContentVersion $version): JsonResponse
    {
        Gate::authorize('rollback', $version);

        $new = $this->versioning->rollback($version, $this->actor($request)->actorId(), null);

        return ApiResponse::created(
            new ContentVersionResource($new->load('sourceVersion')),
            "Rolled back to v{$version->version_number} as a new version.",
        );
    }

    public function clone(CloneVersionRequest $request, ContentVersion $version): JsonResponse
    {
        Gate::authorize('clone', $version);

        $new = $this->versioning->clone($version, $this->actor($request)->actorId(), $request->validated('label'));

        return ApiResponse::created(new ContentVersionResource($new->load('sourceVersion')));
    }

    public function fork(ForkVersionRequest $request, ContentVersion $version): JsonResponse
    {
        Gate::authorize('view', $version); // access to the SOURCE

        $actor = $this->actor($request);
        $destinationId = $this->courses->manageableCourseId($actor, (string) $request->validated('destination_course_id'));

        if ($destinationId === null) {
            throw new NotFoundHttpException('Destination course not found.');
        }

        $new = $this->versioning->fork($version, $destinationId, $actor->actorId(), $request->validated('label'));

        return ApiResponse::created(new ContentVersionResource($new->load('sourceVersion')));
    }

    /** Resolve the course id or 404 — never revealing whether the course exists. */
    private function manageableCourse(Request $request, string $coursePublicId): int
    {
        $courseId = $this->courses->manageableCourseId($this->actor($request), $coursePublicId);

        if ($courseId === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        return $courseId;
    }

    private function actor(Request $request): Actor
    {
        $actor = $request->user();

        if (! $actor instanceof Actor) {
            throw new NotFoundHttpException('Course not found.');
        }

        return $actor;
    }
}
