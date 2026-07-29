<?php

namespace App\Contexts\Learning\Http\Controllers\Api\V1;

use App\Contexts\Learning\Actions\Progress\CompleteBlockAction;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BlockProgressController extends Controller
{
    public function store(Request $request, string $lesson, string $block, CompleteBlockAction $action, CurriculumReadPort $curriculum): JsonResponse
    {
        $ref = $curriculum->findLessonByPublicId($lesson);
        if ($ref === null) {
            throw new NotFoundHttpException('Lesson not found.');
        }

        $progress = $action->executeByUserId($request->user()->id, $ref->id, $block);

        return ApiResponse::success([
            'block_id' => $progress->block_ref,
            'completed_at' => $progress->completed_at?->toIso8601String(),
        ], 'Block completed.');
    }
}
