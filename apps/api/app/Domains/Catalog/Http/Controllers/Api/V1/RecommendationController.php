<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Http\Controllers\Api\V1;

use App\Domains\Catalog\Http\Resources\CourseListResource;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Services\RecommendationService;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public, deterministic recommendation endpoints. Only ever return published + publicly-visible
 * courses. When recommendations are disabled by config, every endpoint returns an empty list rather
 * than an error, so a client can render "nothing to show" uniformly.
 */
final class RecommendationController extends Controller
{
    public function __construct(
        private readonly RecommendationService $recommendations,
    ) {}

    /** "Courses like this one" for a published course, by public id. */
    public function similar(string $publicId): JsonResponse
    {
        $course = $this->resolveCourse($publicId);

        return ApiResponse::success(
            CourseListResource::collection($this->recommendations->similarCourses($course)),
        );
    }

    /** "Popular in this category" for a category, by public id. */
    public function popularInCategory(string $publicId): JsonResponse
    {
        if (! Str::isUuid($publicId)) {
            throw new NotFoundHttpException('Category not found.');
        }

        $category = Category::query()->where('public_id', $publicId)->first();
        if ($category === null) {
            throw new NotFoundHttpException('Category not found.');
        }

        return ApiResponse::success(
            CourseListResource::collection($this->recommendations->popularInCategory($category)),
        );
    }

    private function resolveCourse(string $publicId): Course
    {
        if (! Str::isUuid($publicId)) {
            throw new NotFoundHttpException('Course not found.');
        }

        $course = Course::query()
            ->published()
            ->visible()
            ->with(['categories', 'tags'])
            ->where('public_id', $publicId)
            ->first();

        if ($course === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        return $course;
    }
}
