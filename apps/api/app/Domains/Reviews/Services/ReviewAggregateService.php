<?php

namespace App\Domains\Reviews\Services;

use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Models\CourseReview;
use App\Domains\Reviews\Models\CourseReviewAggregate;
use App\Domains\Reviews\Tenancy\CourseTenantScope;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes and persists a course's rating aggregate. Every mutation that can change the visible
 * review set (create / update / delete / moderate) calls recompute(), which derives the numbers
 * FROM SOURCE inside a transaction and upserts them — so the aggregate is idempotent (running it
 * twice yields the same row) and self-healing (a missed update is corrected by the next recompute).
 *
 * The source count deliberately bypasses the CourseTenantScope: the aggregate is a property of the
 * course itself and must reflect ALL of that course's published reviews regardless of which tenant
 * triggered the recompute.
 */
class ReviewAggregateService
{
    public function recompute(int $courseId): CourseReviewAggregate
    {
        return DB::transaction(function () use ($courseId): CourseReviewAggregate {
            $row = CourseReview::query()
                ->withoutGlobalScope(CourseTenantScope::class)
                ->where('course_id', $courseId)
                ->where('status', ReviewStatus::Published->value)
                ->selectRaw('COUNT(*) as reviews_count')
                ->selectRaw('COALESCE(SUM(rating), 0) as ratings_sum')
                ->selectRaw('COALESCE(SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END), 0) as dist_1')
                ->selectRaw('COALESCE(SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END), 0) as dist_2')
                ->selectRaw('COALESCE(SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END), 0) as dist_3')
                ->selectRaw('COALESCE(SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END), 0) as dist_4')
                ->selectRaw('COALESCE(SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END), 0) as dist_5')
                ->first();

            $count = (int) ($row->reviews_count ?? 0);
            $sum = (int) ($row->ratings_sum ?? 0);
            $avg = $count > 0 ? round($sum / $count, 2) : 0.0;

            /** @var CourseReviewAggregate $aggregate */
            $aggregate = CourseReviewAggregate::query()->updateOrCreate(
                ['course_id' => $courseId],
                [
                    'reviews_count' => $count,
                    'ratings_sum' => $sum,
                    'avg_rating' => $avg,
                    'dist_1' => (int) ($row->dist_1 ?? 0),
                    'dist_2' => (int) ($row->dist_2 ?? 0),
                    'dist_3' => (int) ($row->dist_3 ?? 0),
                    'dist_4' => (int) ($row->dist_4 ?? 0),
                    'dist_5' => (int) ($row->dist_5 ?? 0),
                    'updated_at' => now(),
                ],
            );

            return $aggregate;
        });
    }

    /** The stored aggregate for a course, or a zeroed placeholder when none has been computed yet. */
    public function forCourse(int $courseId): CourseReviewAggregate
    {
        return CourseReviewAggregate::query()->find($courseId) ?? CourseReviewAggregate::empty($courseId);
    }
}
