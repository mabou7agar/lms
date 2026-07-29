<?php

namespace App\Contexts\Learning\Http\Controllers\Api\V1;

use App\Contexts\Learning\Actions\Progress\CompleteLessonAction;
use App\Contexts\Learning\Actions\Progress\MarkLessonViewedAction;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Server-authoritative lesson completion and the "viewed" (start/resume) signal. Completion refuses
 * (422 LEARNING_COMPLETION_BLOCKED) when a required assignment/block/video is outstanding.
 */
class LessonCompletionController extends Controller
{
    public function complete(Request $request, string $lesson, CompleteLessonAction $action, CurriculumReadPort $curriculum): JsonResponse
    {
        $ref = $this->resolve($lesson, $curriculum);

        $progress = $action->executeByUserId($request->user()->id, $ref);

        return ApiResponse::success([
            'status' => $progress->statusEnum()->value,
            'course_progress_percentage' => (int) $progress->enrollment->getAttribute('progress_percentage'),
        ], 'Lesson completed.');
    }

    public function viewed(Request $request, string $lesson, MarkLessonViewedAction $action, CurriculumReadPort $curriculum): JsonResponse
    {
        $ref = $this->resolve($lesson, $curriculum);

        $progress = $action->executeByUserId($request->user()->id, $ref);

        return ApiResponse::success([
            'status' => $progress->statusEnum()->value,
        ], 'Lesson viewed.');
    }

    private function resolve(string $lesson, CurriculumReadPort $curriculum): int
    {
        $ref = $curriculum->findLessonByPublicId($lesson);
        if ($ref === null) {
            throw new NotFoundHttpException('Lesson not found.');
        }

        return $ref->id;
    }
}
