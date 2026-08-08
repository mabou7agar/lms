<?php

namespace App\Domains\Reviews\Http\Resources;

use App\Domains\Reviews\Models\CourseReviewAggregate;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * The per-course rating summary: total count, average, and the 1..5 star distribution. course_id is
 * an internal id and is intentionally omitted.
 *
 * @property CourseReviewAggregate $resource
 */
class CourseReviewAggregateResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reviews_count' => (int) $this->resource->reviews_count,
            'average_rating' => round((float) $this->resource->avg_rating, 2),
            'distribution' => [
                '1' => (int) $this->resource->dist_1,
                '2' => (int) $this->resource->dist_2,
                '3' => (int) $this->resource->dist_3,
                '4' => (int) $this->resource->dist_4,
                '5' => (int) $this->resource->dist_5,
            ],
        ];
    }
}
