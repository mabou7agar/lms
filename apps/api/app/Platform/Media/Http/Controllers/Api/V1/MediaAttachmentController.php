<?php

namespace App\Platform\Media\Http\Controllers\Api\V1;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Media\Http\Requests\AttachMediaRequest;
use App\Platform\Media\Http\Requests\DetachMediaRequest;
use App\Platform\Media\Http\Resources\MediaAttachmentResource;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Services\MediaAttachmentService;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * P2/W04 - Attach/detach a media asset to another context's content. Only the owner or a manager of
 * the asset's course may attach (MediaAssetPolicy 'attach'); the service additionally rejects a
 * cross-course attachment and any non-ready asset.
 */
class MediaAttachmentController
{
    public function __construct(
        private readonly CourseAccessPort $courses,
        private readonly MediaAttachmentService $attachments,
    ) {}

    public function store(AttachMediaRequest $request, MediaAsset $media): JsonResponse
    {
        $actor = $this->authorize($request, $media);

        $courseId = null;
        if (($course = $request->validated('course_id')) !== null) {
            $courseId = $this->courses->manageableCourseId($actor, (string) $course);
            if ($courseId === null) {
                throw new NotFoundHttpException('Course not found.');
            }
        }

        $attachment = $this->attachments->attach(
            asset: $media,
            actorId: $actor->actorId(),
            attachableType: (string) $request->validated('attachable_type'),
            attachableId: (int) $request->validated('attachable_id'),
            role: (string) ($request->validated('role') ?? 'attachment'),
            courseId: $courseId,
        );

        return ApiResponse::created(new MediaAttachmentResource($attachment), 'Media attached.');
    }

    public function destroy(DetachMediaRequest $request, MediaAsset $media): JsonResponse
    {
        $actor = $this->authorize($request, $media);

        $this->attachments->detach(
            asset: $media,
            actorId: $actor->actorId(),
            attachableType: (string) $request->validated('attachable_type'),
            attachableId: (int) $request->validated('attachable_id'),
        );

        return ApiResponse::deleted('Media detached.');
    }

    private function authorize(Request $request, MediaAsset $media): Actor
    {
        $actor = $request->user();

        if (! $actor instanceof Actor || ! Gate::forUser($actor)->allows('attach', $media)) {
            throw new NotFoundHttpException('Media not found.');
        }

        return $actor;
    }
}
