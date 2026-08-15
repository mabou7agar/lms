<?php

namespace App\Domains\Catalog\Http\Controllers\Api\V1;

use App\Domains\Catalog\Http\Resources\CourseListResource;
use App\Domains\Catalog\Http\Resources\InstructorProfileResource;
use App\Domains\Catalog\Http\Resources\TrainerResource;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lists trainers surfaced by the catalog: active users holding the 'instructor' role.
 * Reads through the Identity UserLookupPort (IdentityContracts) — no direct User model access.
 */
class TrainerController extends Controller
{
    public function index(UserLookupPort $users): JsonResponse
    {
        return ApiResponse::success(TrainerResource::collection($users->instructors()));
    }

    /**
     * Public trainer profile page (GET /api/v1/trainers/{publicId}): the instructor's full public
     * profile plus the published, visible courses they teach. Reads the profile through the Identity
     * UserLookupPort (boundary-safe ref) and the courses through the Catalog's own forTrainer scope,
     * keyed by the trainer's internal user id resolved from the same port.
     */
    public function show(string $publicId, UserLookupPort $users): JsonResponse
    {
        if (! Str::isUuid($publicId)) {
            throw new NotFoundHttpException('Trainer not found.');
        }

        $profile = $users->instructorProfileByPublicId($publicId);
        $ref = $users->refByPublicId($publicId);

        if ($profile === null || $ref === null) {
            throw new NotFoundHttpException('Trainer not found.');
        }

        $courses = Course::query()
            ->published()
            ->visible()
            ->forTrainer($ref->id)
            ->with(['level', 'language'])
            ->orderByDesc('published_at')
            ->get();

        return ApiResponse::success([
            'profile' => new InstructorProfileResource($profile),
            'courses' => CourseListResource::collection($courses),
        ]);
    }
}
