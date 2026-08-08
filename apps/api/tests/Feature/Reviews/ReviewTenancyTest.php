<?php

declare(strict_types=1);

use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Models\Course;
use App\Domains\Reviews\Models\CourseReview;
use App\Domains\Reviews\Services\ReviewAggregateService;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * T1 Option-N adversarial matrix for course reviews: a review of an org1 course is never visible or
 * creatable under an org2 tenant, mirroring CourseTenancyTest. Reviews inherit tenancy transitively
 * from their course (Reviews\Tenancy\CourseTenantScope joins to `courses`), carrying no tenant column
 * of their own.
 */
beforeEach(fn () => app(TenantContext::class)->forget());
afterEach(fn () => app(TenantContext::class)->forget());

function reviewCourseForOrg(?int $org): Course
{
    $context = app(TenantContext::class);

    if ($org === null) {
        $context->forget();

        return Course::factory()->published()->create();
    }

    $context->set(TenantId::from($org));
    $course = Course::factory()->published()->create();
    $context->forget();

    return $course;
}

/** Create a published review on a course, tenancy bypassed so seeding is context-free. */
function reviewOn(Course $course): CourseReview
{
    return app(TenantContext::class)->runWithoutTenancy(function () use ($course): CourseReview {
        $review = CourseReview::factory()->create([
            'course_id' => $course->id,
            'user_id' => User::factory()->create()->id,
        ]);

        // Factory seeding bypasses CreateReviewAction (the sole production write path, which maintains
        // the aggregate); recompute so the denormalized aggregate matches source, as it would in prod.
        app(ReviewAggregateService::class)->recompute((int) $course->id);

        return $review;
    });
}

it('hides an org1 course review from an org2 tenant at the model boundary', function (): void {
    $org1Course = reviewCourseForOrg(1);
    reviewOn($org1Course);

    app(TenantContext::class)->set(TenantId::from(2));

    expect(CourseReview::query()->where('course_id', $org1Course->id)->exists())->toBeFalse();

    app(TenantContext::class)->set(TenantId::from(1));

    expect(CourseReview::query()->where('course_id', $org1Course->id)->exists())->toBeTrue();
});

it('returns 404 listing an org1 course review under an org2 tenant (HTTP boundary)', function (): void {
    $org1Course = reviewCourseForOrg(1);
    reviewOn($org1Course);

    // An org2-resolved request must not even see the course exists.
    app(TenantContext::class)->set(TenantId::from(2));
    $this->getJson("/api/v1/courses/{$org1Course->public_id}/reviews")->assertNotFound();

    // The owning org1 tenant sees its own course's reviews.
    app(TenantContext::class)->set(TenantId::from(1));
    $this->getJson("/api/v1/courses/{$org1Course->public_id}/reviews")
        ->assertOk()
        ->assertJsonPath('meta.aggregate.reviews_count', 1);
});

it('forbids creating a review on an org1 course from an org2 tenant (404)', function (): void {
    $org1Course = reviewCourseForOrg(1);

    $learner = User::factory()->create(['organization_id' => 2]);
    Enrollment::factory()->create(['user_id' => $learner->id, 'course_id' => $org1Course->id]);

    Sanctum::actingAs($learner);
    app(TenantContext::class)->set(TenantId::from(2));

    $this->postJson("/api/v1/courses/{$org1Course->public_id}/reviews", ['rating' => 5])->assertNotFound();
});

it('leaves review reads unscoped when no tenant is resolved (backward compatible)', function (): void {
    $org1Course = reviewCourseForOrg(1);
    $org2Course = reviewCourseForOrg(2);
    reviewOn($org1Course);
    reviewOn($org2Course);

    app(TenantContext::class)->forget();

    expect(CourseReview::query()->count())->toBe(2);
});
