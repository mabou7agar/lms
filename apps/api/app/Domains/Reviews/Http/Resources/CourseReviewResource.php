<?php

namespace App\Domains\Reviews\Http\Resources;

use App\Domains\Reviews\Models\CourseReview;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Public representation of a course review. Internal ids and the tenant stamp are never exposed;
 * only the external public_id and the learner-facing fields are rendered.
 *
 * @property CourseReview $resource
 */
class CourseReviewResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'rating' => (int) $this->resource->rating,
            'body' => $this->resource->body,
            'status' => $this->resource->status->value,
            'verified' => (bool) $this->resource->verified,
            'helpful_count' => (int) $this->resource->helpful_count,
            'instructor_response' => $this->resource->instructor_response,
            'responded_at' => $this->resource->responded_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
