<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Learning\Contracts\EnrollmentStatsPort;
use App\Platform\Shared\Learning\Contracts\LearnerHistoryPort;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Deterministic course recommendations (v1 — NO AI required). Lives in Catalog so it can read course
 * taxonomy directly, and reaches enrollment/history signals only through Shared ports
 * (EnrollmentStatsPort, LearnerHistoryPort) — never Learning's tables (Deptrac: Catalog -> Shared).
 *
 * Every surface is:
 *   - PUBLISHED + publicly-visible only (reuses Course::published()->visible());
 *   - deterministic (stable tie-breaks by course id), so the same inputs always yield the same order;
 *   - globally disable-able via config('catalog.recommendations.enabled') — the admin kill switch.
 *
 * Embedding-based enrichment (semantic "more like this") is intentionally deferred; the category/tag
 * and enrollment signals here are sufficient and predictable for v1.
 */
final class RecommendationService extends BaseService
{
    public function __construct(
        private readonly RelatedCoursesService $related,
        private readonly EnrollmentStatsPort $enrollmentStats,
        private readonly LearnerHistoryPort $history,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('catalog.recommendations.enabled', true);
    }

    /**
     * "Courses like this one" — shared category/tag, deterministic order. Also powers
     * "because you completed X" (pass the completed course).
     *
     * @return Collection<int, Course>
     */
    public function similarCourses(Course $course, ?int $limit = null): Collection
    {
        if (! $this->enabled()) {
            return new Collection;
        }

        $limit ??= $this->limit();

        // Reuse the related-courses rule (shared category/tag, published+public, excludes self), then
        // impose a deterministic order the underlying limited query does not guarantee.
        return $this->related->for($course, $limit * 3)
            ->sortBy('id')
            ->take($limit)
            ->values();
    }

    /**
     * "Because you completed {course}" — identical signal to similarCourses, distinct surface/label.
     *
     * @return Collection<int, Course>
     */
    public function becauseYouCompleted(Course $completed, ?int $limit = null): Collection
    {
        return $this->similarCourses($completed, $limit);
    }

    /**
     * "Popular in {category}" — published+public courses in the category, ranked by total enrollments
     * (via the Shared EnrollmentStatsPort), deterministic tie-break by id.
     *
     * @return Collection<int, Course>
     */
    public function popularInCategory(Category $category, ?int $limit = null): Collection
    {
        if (! $this->enabled()) {
            return new Collection;
        }

        $limit ??= $this->limit();

        /** @var Collection<int, Course> $courses */
        $courses = Course::query()
            ->published()
            ->visible()
            ->whereHas('categories', fn (Builder $q) => $q->whereKey($category->getKey()))
            ->with(['level', 'language'])
            ->get();

        if ($courses->isEmpty()) {
            return new Collection;
        }

        $stats = $this->enrollmentStats->statsPerCourse(
            $courses->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );

        return $courses
            ->sortBy(static fn (Course $c): array => [
                -1 * ($stats[(int) $c->getKey()]->enrollments ?? 0), // most enrolled first
                (int) $c->getKey(),                                  // stable tie-break
            ])
            ->take($limit)
            ->values();
    }

    /**
     * "Continue learning" — the learner's in-progress courses (via LearnerHistoryPort), in the port's
     * order, filtered to still-published+public courses.
     *
     * @return Collection<int, Course>
     */
    public function continueLearning(int $userId, ?int $limit = null): Collection
    {
        if (! $this->enabled()) {
            return new Collection;
        }

        return $this->coursesInOrder($this->history->inProgressCourseIds($userId), $limit ?? $this->limit());
    }

    /**
     * "Next course" — courses similar to a just-completed one, excluding anything the learner already
     * enrolled in.
     *
     * @return Collection<int, Course>
     */
    public function nextCourse(int $userId, Course $justCompleted, ?int $limit = null): Collection
    {
        if (! $this->enabled()) {
            return new Collection;
        }

        $limit ??= $this->limit();
        $taken = array_flip($this->history->enrolledCourseIds($userId));

        return $this->similarCourses($justCompleted, $limit * 3)
            ->reject(static fn (Course $c): bool => isset($taken[$c->getKey()]))
            ->take($limit)
            ->values();
    }

    /**
     * Load courses for the given ids preserving that order, keeping only published+public ones.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Course>
     */
    private function coursesInOrder(array $ids, int $limit): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $byId = Course::query()
            ->published()
            ->visible()
            ->whereIn('id', $ids)
            ->with(['level', 'language'])
            ->get()
            ->keyBy('id');

        $ordered = new Collection;
        foreach ($ids as $id) {
            if ($byId->has($id)) {
                $ordered->push($byId->get($id));
            }
        }

        return $ordered->take($limit)->values();
    }

    private function limit(): int
    {
        return (int) config('catalog.recommendations.limit', 8);
    }
}
