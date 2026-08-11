<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Services\RecommendationService;
use App\Platform\Shared\Learning\Contracts\EnrollmentStatsPort;
use App\Platform\Shared\Learning\Data\EnrollmentStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['catalog.recommendations.enabled' => true]);
});

/**
 * Fake enrollment stats so "popular" ranking is deterministic and independent of the Learning context.
 *
 * @param  array<int, int>  $enrollmentsByCourseId
 */
function fakeEnrollmentStats(array $enrollmentsByCourseId): EnrollmentStatsPort
{
    return new class($enrollmentsByCourseId) implements EnrollmentStatsPort
    {
        /** @param array<int, int> $map */
        public function __construct(private array $map) {}

        public function statsForCourses(array $courseIds, ?string $from = null, ?string $to = null): EnrollmentStats
        {
            return EnrollmentStats::empty();
        }

        /** @return array<int, EnrollmentStats> */
        public function statsPerCourse(array $courseIds, ?string $from = null, ?string $to = null): array
        {
            $out = [];
            foreach ($courseIds as $id) {
                $out[$id] = new EnrollmentStats((int) ($this->map[$id] ?? 0), 0, 0, 0, 0);
            }

            return $out;
        }
    };
}

it('recommends similar courses deterministically by shared category, excluding the source', function () {
    $category = Category::factory()->create();

    $source = Course::factory()->published()->create();
    $b = Course::factory()->published()->create();
    $d = Course::factory()->published()->create();
    foreach ([$source, $b, $d] as $course) {
        $course->categories()->attach($category->id);
    }

    $service = app(RecommendationService::class);

    $first = $service->similarCourses($source)->pluck('id')->all();
    $second = $service->similarCourses($source)->pluck('id')->all();

    expect($first)->toBe([$b->id, $d->id])   // deterministic, id-ordered
        ->and($first)->toBe($second)          // stable across calls
        ->and($first)->not->toContain($source->id);
});

it('produces "because you completed X" recommendations from the same signal', function () {
    $category = Category::factory()->create();
    $completed = Course::factory()->published()->create();
    $other = Course::factory()->published()->create();
    $completed->categories()->attach($category->id);
    $other->categories()->attach($category->id);

    $recs = app(RecommendationService::class)->becauseYouCompleted($completed);

    expect($recs->pluck('id')->all())->toBe([$other->id]);
});

it('ranks "popular in category" by enrollments deterministically', function () {
    $category = Category::factory()->create();
    $low = Course::factory()->published()->create();
    $high = Course::factory()->published()->create();
    $mid = Course::factory()->published()->create();
    foreach ([$low, $high, $mid] as $course) {
        $course->categories()->attach($category->id);
    }

    app()->bind(EnrollmentStatsPort::class, fn () => fakeEnrollmentStats([
        $high->id => 10,
        $mid->id => 5,
        $low->id => 1,
    ]));

    $ranked = app(RecommendationService::class)->popularInCategory($category)->pluck('id')->all();

    expect($ranked)->toBe([$high->id, $mid->id, $low->id]);
});

it('hides every recommendation surface when the admin disable flag is off', function () {
    config(['catalog.recommendations.enabled' => false]);

    $category = Category::factory()->create();
    $source = Course::factory()->published()->create();
    $other = Course::factory()->published()->create();
    $source->categories()->attach($category->id);
    $other->categories()->attach($category->id);

    $service = app(RecommendationService::class);

    expect($service->similarCourses($source))->toBeEmpty()
        ->and($service->becauseYouCompleted($source))->toBeEmpty()
        ->and($service->popularInCategory($category))->toBeEmpty();
});
