<?php

declare(strict_types=1);

use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Reviews\Models\CourseReview;
use App\Domains\Reviews\Services\ReviewAggregateService;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Moderation\Enums\ReportStatus;
use App\Platform\Shared\Moderation\Models\ContentReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

function reviewCourse(bool $published = true): Course
{
    return $published ? Course::factory()->published()->create() : Course::factory()->create();
}

function enroll(User $user, Course $course): Enrollment
{
    return Enrollment::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);
}

/** An enrolled learner (not a trainer of the course). */
function learnerFor(Course $course): User
{
    $user = User::factory()->create();
    enroll($user, $course);

    return $user;
}

it('lets an enrolled learner create a review and lists it with the aggregate', function (): void {
    $course = reviewCourse();
    $learner = learnerFor($course);

    Sanctum::actingAs($learner);
    $created = $this->postJson("/api/v1/courses/{$course->public_id}/reviews", [
        'rating' => 5,
        'body' => 'Excellent course.',
    ])->assertCreated();

    expect($created->json('data.rating'))->toBe(5)
        ->and($created->json('data.verified'))->toBeTrue();

    $list = $this->getJson("/api/v1/courses/{$course->public_id}/reviews")->assertOk();

    expect($list->json('data'))->toHaveCount(1)
        ->and((int) $list->json('meta.aggregate.reviews_count'))->toBe(1)
        ->and((float) $list->json('meta.aggregate.average_rating'))->toBe(5.0)
        ->and((int) $list->json('meta.aggregate.distribution.5'))->toBe(1);
});

it('enforces one active review per learner per course (409)', function (): void {
    $course = reviewCourse();
    $learner = learnerFor($course);

    Sanctum::actingAs($learner);
    $this->postJson("/api/v1/courses/{$course->public_id}/reviews", ['rating' => 4])->assertCreated();
    $this->postJson("/api/v1/courses/{$course->public_id}/reviews", ['rating' => 3])->assertStatus(409);
});

it('forbids a non-enrolled user from reviewing (403)', function (): void {
    $course = reviewCourse();
    $stranger = User::factory()->create();

    Sanctum::actingAs($stranger);
    $this->postJson("/api/v1/courses/{$course->public_id}/reviews", ['rating' => 5])->assertStatus(403);
});

it('forbids the course instructor from reviewing their own course (403)', function (): void {
    $course = reviewCourse();
    $instructor = User::factory()->create();
    $instructor->assignRole(SpatieRole::findByName(Role::Instructor->value, 'web'));
    $course->syncTrainers([$instructor->id]);
    enroll($instructor, $course); // even enrolled, the instructor branch must reject.

    Sanctum::actingAs($instructor);
    $this->postJson("/api/v1/courses/{$course->public_id}/reviews", ['rating' => 5])->assertStatus(403);
});

it('sanitizes script tags out of the review body', function (): void {
    $course = reviewCourse();
    $learner = learnerFor($course);

    Sanctum::actingAs($learner);
    $res = $this->postJson("/api/v1/courses/{$course->public_id}/reviews", [
        'rating' => 4,
        'body' => '<p>Nice</p><script>alert(1)</script>',
    ])->assertCreated();

    $body = (string) $res->json('data.body');
    expect($body)->toContain('Nice')
        ->and($body)->not->toContain('<script');

    $stored = (string) CourseReview::query()->first()->body;
    expect($stored)->not->toContain('<script');
});

it('allows updating and deleting only your own review (IDOR negative)', function (): void {
    $course = reviewCourse();
    $owner = learnerFor($course);
    $other = learnerFor($course);

    Sanctum::actingAs($owner);
    $review = $this->postJson("/api/v1/courses/{$course->public_id}/reviews", ['rating' => 3])
        ->assertCreated()->json('data.id');

    // Another learner may not update or delete it.
    Sanctum::actingAs($other);
    $this->patchJson("/api/v1/reviews/{$review}", ['rating' => 1])->assertStatus(403);
    $this->deleteJson("/api/v1/reviews/{$review}")->assertStatus(403);

    // The owner may.
    Sanctum::actingAs($owner);
    $this->patchJson("/api/v1/reviews/{$review}", ['rating' => 5])->assertOk()->assertJsonPath('data.rating', 5);
    $this->deleteJson("/api/v1/reviews/{$review}")->assertOk();

    expect(CourseReview::query()->count())->toBe(0);
});

it('lets the course instructor respond to a review but forbids others', function (): void {
    $course = reviewCourse();
    $learner = learnerFor($course);
    $instructor = User::factory()->create();
    $instructor->assignRole(SpatieRole::findByName(Role::Instructor->value, 'web'));
    $course->syncTrainers([$instructor->id]);

    Sanctum::actingAs($learner);
    $review = $this->postJson("/api/v1/courses/{$course->public_id}/reviews", ['rating' => 4])
        ->assertCreated()->json('data.id');

    // A random learner cannot respond.
    Sanctum::actingAs($learner);
    $this->postJson("/api/v1/reviews/{$review}/respond", ['response' => 'Thanks'])->assertStatus(403);

    // The instructor can.
    Sanctum::actingAs($instructor);
    $this->postJson("/api/v1/reviews/{$review}/respond", ['response' => 'Thank you for the feedback!'])
        ->assertOk()
        ->assertJsonPath('data.instructor_response', 'Thank you for the feedback!');
});

it('makes helpful votes idempotent', function (): void {
    $course = reviewCourse();
    $author = learnerFor($course);
    $voter = learnerFor($course);

    Sanctum::actingAs($author);
    $review = $this->postJson("/api/v1/courses/{$course->public_id}/reviews", ['rating' => 5])
        ->assertCreated()->json('data.id');

    Sanctum::actingAs($voter);
    $this->postJson("/api/v1/reviews/{$review}/helpful")->assertOk()->assertJsonPath('data.helpful_count', 1);
    // A second identical vote does not inflate the count.
    $this->postJson("/api/v1/reviews/{$review}/helpful")->assertOk()->assertJsonPath('data.helpful_count', 1);
});

it('recomputes the aggregate including the distribution', function (): void {
    $course = reviewCourse();

    // Seed reviews with a spread of ratings for distinct learners.
    foreach ([5, 5, 4, 2] as $stars) {
        $user = User::factory()->create();
        CourseReview::factory()->rating($stars)->create(['course_id' => $course->id, 'user_id' => $user->id]);
    }
    // A hidden review must NOT count toward the aggregate.
    CourseReview::factory()->rating(1)->hidden()->create([
        'course_id' => $course->id,
        'user_id' => User::factory()->create()->id,
    ]);

    $aggregate = app(ReviewAggregateService::class)->recompute($course->id);

    expect($aggregate->reviews_count)->toBe(4)
        ->and($aggregate->ratings_sum)->toBe(16)
        ->and((float) $aggregate->avg_rating)->toBe(4.0)
        ->and($aggregate->dist_5)->toBe(2)
        ->and($aggregate->dist_4)->toBe(1)
        ->and($aggregate->dist_2)->toBe(1)
        ->and($aggregate->dist_1)->toBe(0);

    // Idempotent: a second recompute yields the same numbers.
    $again = app(ReviewAggregateService::class)->recompute($course->id);
    expect($again->reviews_count)->toBe(4)->and((float) $again->avg_rating)->toBe(4.0);
});

it('creates a content report when a review is reported and is idempotent per reporter', function (): void {
    $course = reviewCourse();
    $author = learnerFor($course);
    $reporter = learnerFor($course);

    Sanctum::actingAs($author);
    $review = $this->postJson("/api/v1/courses/{$course->public_id}/reviews", ['rating' => 1, 'body' => 'spammy'])
        ->assertCreated()->json('data.id');

    Sanctum::actingAs($reporter);
    $this->postJson("/api/v1/reviews/{$review}/report", ['reason' => 'spam', 'note' => 'looks like spam'])
        ->assertCreated();
    // A repeat report while the first is still pending does not stack.
    $this->postJson("/api/v1/reviews/{$review}/report", ['reason' => 'spam'])->assertCreated();

    expect(ContentReport::query()->count())->toBe(1)
        ->and(ContentReport::query()->first()->status)->toBe(ReportStatus::Pending)
        ->and(ContentReport::query()->first()->reportable_type)->toBe(CourseReview::class);
});
