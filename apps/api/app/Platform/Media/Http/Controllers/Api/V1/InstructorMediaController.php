<?php

namespace App\Platform\Media\Http\Controllers\Api\V1;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Media\Http\Requests\CreateDirectUploadRequest;
use App\Platform\Media\Http\Requests\FinalizeUploadRequest;
use App\Platform\Media\Http\Resources\DirectUploadTicketResource;
use App\Platform\Media\Http\Resources\MediaAssetResource;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Ports\MediaAssetRefResolver;
use App\Platform\Media\Services\MediaDeletionService;
use App\Platform\Media\Services\MediaIngestionService;
use App\Platform\Media\Services\MediaUploadService;
use App\Platform\Shared\Media\Contracts\PlaybackPort;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * P2/W04 - Instructor-facing media library + ingestion API. All routes are authenticated; the
 * request user is an Actor. Owner-or-course-manager authorization goes through MediaAssetPolicy, and
 * a denied/absent asset always yields 404 (no existence leak). Internal ids and storage/provider
 * identifiers are never exposed.
 */
class InstructorMediaController
{
    public function __construct(
        private readonly CourseAccessPort $courses,
        private readonly MediaUploadService $uploads,
        private readonly MediaIngestionService $ingestion,
        private readonly MediaDeletionService $deletion,
    ) {}

    /** Paginated, filtered library of the actor's own media. */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);

        $query = MediaAsset::query()->ownedBy($actor->actorId())->orderByDesc('id');

        if (($type = $request->query('type')) !== null && ($enum = MediaType::tryFrom((string) $type)) !== null) {
            $query->where('type', $enum->value);
        }

        if (($status = $request->query('status')) !== null && ($enum = MediaStatus::tryFrom((string) $status)) !== null) {
            $query->where('status', $enum->value);
        }

        if (($course = $request->query('course_id')) !== null) {
            $courseId = $this->courses->manageableCourseId($actor, (string) $course);
            // Unknown/unowned course must not silently list everything.
            $query->where('course_id', $courseId ?? -1);
        }

        return ApiResponse::paginated($query->paginate($perPage), MediaAssetResource::class);
    }

    /** Create a direct-upload slot (returns provider instructions + single-use finalize token). */
    public function store(CreateDirectUploadRequest $request): JsonResponse
    {
        $actor = $this->actor($request);

        $courseId = null;
        if (($course = $request->validated('course_id')) !== null) {
            $courseId = $this->courses->manageableCourseId($actor, (string) $course);
            if ($courseId === null) {
                throw new NotFoundHttpException('Course not found.');
            }
        }

        $ticket = $this->uploads->createDirectUpload(
            actorId: $actor->actorId(),
            type: MediaType::from((string) $request->validated('type')),
            purpose: MediaPurpose::from((string) $request->validated('purpose')),
            filename: (string) $request->validated('filename'),
            mimeType: (string) $request->validated('mime_type'),
            sizeBytes: (int) $request->validated('size_bytes'),
            courseId: $courseId,
            idempotencyKey: (string) $request->validated('idempotency_key'),
        );

        return ApiResponse::created(new DirectUploadTicketResource($ticket));
    }

    /** Confirm an upload; spends the single-use token and reads authoritative provider state. */
    public function finalize(FinalizeUploadRequest $request, MediaAsset $media): JsonResponse
    {
        $this->authorizeManage($request, $media, 'update');

        $updated = $this->ingestion->finalizeUpload($media, (string) $request->validated('upload_token'));

        return ApiResponse::success(new MediaAssetResource($updated), 'Upload finalized.');
    }

    public function show(Request $request, MediaAsset $media): JsonResponse
    {
        $this->authorizeManage($request, $media, 'view');

        return ApiResponse::success(new MediaAssetResource($media));
    }

    public function retry(Request $request, MediaAsset $media): JsonResponse
    {
        $this->authorizeManage($request, $media, 'retry');

        $updated = $this->ingestion->retry($media);

        return ApiResponse::success(new MediaAssetResource($updated), 'Retry initiated.');
    }

    public function destroy(Request $request, MediaAsset $media): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeManage($request, $media, 'delete');

        $this->deletion->deleteAsset($media, $actor->actorId(), (bool) $request->boolean('force'));

        return ApiResponse::deleted('Media deleted.');
    }

    /**
     * Short-lived signed URL for a single asset, for graders viewing a submission file (and owners).
     * Authorized through the MediaAssetPolicy 'playback' ability: the asset must be READY and the
     * viewer must own it, may manage its course (CourseAccessPort), or has course access
     * (MediaEnrollmentPort). A denial is a 404. Only the signed url + expiry + kind leave the server.
     */
    public function signedUrl(Request $request, MediaAsset $media, PlaybackPort $playback, MediaAssetRefResolver $refs): JsonResponse
    {
        $this->authorizeManage($request, $media, 'playback');

        $token = $playback->issue($refs->refForAsset($media), (int) config('learning.playback.ttl_seconds', 600));

        return ApiResponse::success([
            'url' => $token->url,
            'expires_at' => $token->expiresAt->toIso8601String(),
            'kind' => $token->kind,
        ]);
    }

    /** Authorize via MediaAssetPolicy, converting a denial into a 404 (no existence leak). */
    private function authorizeManage(Request $request, MediaAsset $media, string $ability): void
    {
        if (! Gate::forUser($this->actor($request))->allows($ability, $media)) {
            throw new NotFoundHttpException('Media not found.');
        }
    }

    private function actor(Request $request): Actor
    {
        $actor = $request->user();

        if (! $actor instanceof Actor) {
            throw new NotFoundHttpException('Media not found.');
        }

        return $actor;
    }
}
