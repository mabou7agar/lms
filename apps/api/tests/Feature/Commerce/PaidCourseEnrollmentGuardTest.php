<?php

use App\Contexts\Commerce\Enums\ProductStatus;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Learning\Actions\Enrollment\EnrollInCourseAction;
use App\Contexts\Learning\Actions\Enrollment\GrantEnrollmentAction;
use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Contexts\Learning\Exceptions\CoursePurchaseRequiredException;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Commerce\Contracts\EntitlementPort;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function enrollableCourse(): Course
{
    return Course::factory()->create([
        'status' => CourseStatus::Published->value,
        'published_at' => now()->subDay(),
    ]);
}

function sellCourse(Course $course, string $status = ProductStatus::Active->value): Product
{
    $product = Product::factory()->create(['status' => $status]);
    $product->courses()->sync([(int) $course->id]);

    return $product;
}

it('refuses payment-free self-enrolment into a course that is sold', function (): void {
    $user = User::factory()->create();
    $course = enrollableCourse();
    sellCourse($course);

    app(EnrollInCourseAction::class)->executeByUserId((int) $user->id, (int) $course->id);
})->throws(CoursePurchaseRequiredException::class);

it('still allows self-enrolment into a course nothing sells', function (): void {
    $user = User::factory()->create();
    $course = enrollableCourse();

    $enrollment = app(EnrollInCourseAction::class)->executeByUserId((int) $user->id, (int) $course->id);

    expect($enrollment->exists)->toBeTrue()
        ->and((int) $enrollment->user_id)->toBe((int) $user->id);
});

it('does not lock a course away while its product is still a draft', function (): void {
    $user = User::factory()->create();
    $course = enrollableCourse();
    sellCourse($course, ProductStatus::Draft->value);

    $enrollment = app(EnrollInCourseAction::class)->executeByUserId((int) $user->id, (int) $course->id);

    expect($enrollment->exists)->toBeTrue();
});

it('lets a buyer who already holds the entitlement enrol without paying again', function (): void {
    $user = User::factory()->create();
    $course = enrollableCourse();
    sellCourse($course);

    // Stand in for a fulfilled purchase: the port reports the entitlement the order created.
    $this->mock(EntitlementPort::class, function ($mock) {
        $mock->shouldReceive('isCoursePurchasable')->andReturn(true);
        $mock->shouldReceive('hasCourseEntitlement')->andReturn(true);
    });

    $enrollment = app(EnrollInCourseAction::class)->executeByUserId((int) $user->id, (int) $course->id);

    expect($enrollment->exists)->toBeTrue();
});

it('leaves the paid and company grant path untouched for a sold course', function (): void {
    $user = User::factory()->create();
    $course = enrollableCourse();
    sellCourse($course);

    // Order fulfilment and manager assignment both go straight to GrantEnrollmentAction, which the
    // purchase guard deliberately does not sit in front of.
    $enrollment = app(GrantEnrollmentAction::class)
        ->executeByUserId((int) $user->id, (int) $course->id, EnrollmentSource::Purchase);

    expect($enrollment->exists)->toBeTrue()
        ->and($enrollment->source)->toBe(EnrollmentSource::Purchase);
});

it('reports purchasability through the shared port', function (): void {
    $sold = enrollableCourse();
    $free = enrollableCourse();
    sellCourse($sold);

    $port = app(EntitlementPort::class);

    expect($port->isCoursePurchasable((int) $sold->id))->toBeTrue()
        ->and($port->isCoursePurchasable((int) $free->id))->toBeFalse();
});
