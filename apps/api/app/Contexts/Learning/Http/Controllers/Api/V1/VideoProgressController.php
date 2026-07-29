<?php

namespace App\Contexts\Learning\Http\Controllers\Api\V1;

use App\Contexts\Learning\Actions\Progress\RecordVideoProgressAction;
use App\Contexts\Learning\Http\Requests\RecordVideoProgressRequest;
use App\Contexts\Learning\Http\Resources\VideoProgressResource;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class VideoProgressController extends Controller
{
    public function store(RecordVideoProgressRequest $request, string $lesson, RecordVideoProgressAction $action, CurriculumReadPort $curriculum): JsonResponse
    {
        $ref = $curriculum->findLessonByPublicId($lesson);
        if ($ref === null) {
            throw new NotFoundHttpException('Lesson not found.');
        }

        $data = $request->validated();
        $progress = $action->executeByUserId(
            $request->user()->id,
            $ref->id,
            (int) $data['position_seconds'],
            isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null,
        );

        return ApiResponse::success(new VideoProgressResource($progress), 'Video progress recorded.');
    }
}
