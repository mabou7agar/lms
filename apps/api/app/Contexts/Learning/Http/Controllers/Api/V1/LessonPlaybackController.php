<?php

namespace App\Contexts\Learning\Http\Controllers\Api\V1;

use App\Contexts\Learning\Services\LearningMediaService;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Just-in-time signed playback for a lesson's primary media. Split from the full lesson payload so
 * the player can re-sign an expired URL without re-fetching the whole lesson. Access
 * (enrollment + prerequisites + drip) is enforced inside LearningMediaService before a token is
 * ever issued; the response carries only the signed url + expiry + kind — never a storage id.
 */
class LessonPlaybackController extends Controller
{
    public function show(Request $request, string $lesson, LearningMediaService $media, CurriculumReadPort $curriculum): JsonResponse
    {
        $user = $request->user();

        $ref = $curriculum->findLessonByPublicId($lesson);
        if ($ref === null) {
            throw new NotFoundHttpException('Lesson not found.');
        }

        // Authorizes enrollment/prerequisites and throws MediaUnavailableException when the lesson
        // has no signable media.
        $token = $media->playbackForLessonByUserId((int) $user->id, $ref->id);

        return ApiResponse::success([
            'url' => $token->url,
            'expires_at' => $token->expiresAt->toIso8601String(),
            'provider' => $token->kind,
        ]);
    }
}
