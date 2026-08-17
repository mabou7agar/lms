<?php

declare(strict_types=1);

use App\Contexts\Commerce\Actions\Payment\FulfillOrderAction;
use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\OrderItem;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Learning\Models\Enrollment;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * A product an admin sold as "12 months access" has to stop after twelve months. The access policy
 * was honoured for company entitlements from the seat wave onwards, but an individual buyer was
 * quietly granted lifetime access whatever the admin had configured.
 */
uses(RefreshDatabase::class);
require_once __DIR__.'/CommerceHelpers.php';

function timedProduct(array $policy): array
{
    [$course, $product] = courseProduct(19900);
    $product->forceFill(array_merge(['audience' => 'both'], $policy))->save();

    return [$product->refresh(), $course];
}

function fulfilIndividual(Product $product, User $buyer): Order
{
    $order = Order::create([
        'user_id' => $buyer->id,
        'status' => OrderStatus::Paid->value,
        'currency' => 'SAR',
        'subtotal_minor' => 19900, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 19900,
        'placed_at' => now(), 'paid_at' => now(),
        'buyer_type' => BuyerType::Individual->value,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'title' => $product->title,
        'unit_amount_minor' => 19900,
    ]);

    app(FulfillOrderAction::class)->execute($order);

    return $order->refresh();
}

it('dates an enrollment from a product sold with a fixed access window', function (): void {
    [$product, $course] = timedProduct([
        'access_duration_type' => 'fixed_months',
        'access_duration_value' => 12,
    ]);
    $buyer = User::factory()->create();

    fulfilIndividual($product, $buyer);

    $enrollment = Enrollment::where('user_id', $buyer->id)->where('course_id', $course->id)->firstOrFail();

    expect($enrollment->expires_at)->not->toBeNull()
        ->and($enrollment->expires_at->toDateString())->toBe(now()->addMonths(12)->toDateString());
});

it('leaves a lifetime product open-ended', function (): void {
    [$product, $course] = timedProduct(['access_duration_type' => 'lifetime']);
    $buyer = User::factory()->create();

    fulfilIndividual($product, $buyer);

    expect(Enrollment::where('user_id', $buyer->id)->where('course_id', $course->id)->firstOrFail()->expires_at)
        ->toBeNull();
});

it('leaves a product left at the column default open-ended', function (): void {
    // A product created before the commercial-policy wave took the column default rather than an
    // explicit choice. The column is NOT NULL and defaults to lifetime, so "never configured" and
    // "configured as lifetime" are the same row — and neither may suddenly start expiring.
    [$product, $course] = timedProduct([]);
    $buyer = User::factory()->create();

    expect($product->access_duration_type?->value)->toBe('lifetime');

    fulfilIndividual($product, $buyer);

    expect(Enrollment::where('user_id', $buyer->id)->where('course_id', $course->id)->firstOrFail()->expires_at)
        ->toBeNull();
});

it('denies the course player once individual access has run out', function (): void {
    [$product, $course] = timedProduct([
        'access_duration_type' => 'fixed_days',
        'access_duration_value' => 30,
    ]);
    $buyer = User::factory()->create();
    fulfilIndividual($product, $buyer);

    Enrollment::where('user_id', $buyer->id)->update(['expires_at' => now()->subDay()]);

    Sanctum::actingAs($buyer);

    $this->getJson("/api/v1/courses/{$course->public_id}/learn")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'LEARNING_ACCESS_EXPIRED');
});

it('closes every other entitled surface at the same moment', function (): void {
    // Files, Q&A and assessments all decide entitlement through CourseEnrollmentPort, so an expired
    // individual purchase must fail there too rather than only in the player.
    [$product, $course] = timedProduct([
        'access_duration_type' => 'fixed_days',
        'access_duration_value' => 30,
    ]);
    $buyer = User::factory()->create();
    fulfilIndividual($product, $buyer);

    $port = app(CourseEnrollmentPort::class);
    expect($port->hasCourseAccess($course->id, $buyer->id))->toBeTrue();

    Enrollment::where('user_id', $buyer->id)->update(['expires_at' => now()->subDay()]);

    expect($port->hasCourseAccess($course->id, $buyer->id))->toBeFalse()
        ->and($port->isEnrolled($course->id, $buyer->id))->toBeFalse();

    Sanctum::actingAs($buyer);
    $this->getJson("/api/v1/courses/{$course->public_id}/questions")->assertForbidden();
});

it('shuts the player runtime, not only the launch check', function (): void {
    // The runtime endpoints resolve their enrollment through LessonAccessService rather than the
    // launch guard, and that query looked only at status. So the browser was refused at the door
    // and then served the whole curriculum, resume pointer and progress by the endpoints the player
    // actually calls.
    [$product, $course] = timedProduct([
        'access_duration_type' => 'fixed_days',
        'access_duration_value' => 30,
    ]);
    $buyer = User::factory()->create();
    fulfilIndividual($product, $buyer);

    Sanctum::actingAs($buyer);
    $this->getJson("/api/v1/courses/{$course->public_id}/curriculum")->assertOk();

    Enrollment::where('user_id', $buyer->id)->update(['expires_at' => now()->subDay()]);

    $this->getJson("/api/v1/courses/{$course->public_id}/curriculum")->assertForbidden();
    $this->getJson("/api/v1/courses/{$course->public_id}/resume")->assertForbidden();
    $this->getJson("/api/v1/courses/{$course->public_id}/progress-summary")->assertForbidden();
    $this->postJson("/api/v1/courses/{$course->public_id}/launch")->assertForbidden();
});

it('marks an expired individual purchase in My Learning', function (): void {
    [$product] = timedProduct([
        'access_duration_type' => 'fixed_days',
        'access_duration_value' => 30,
    ]);
    $buyer = User::factory()->create();
    fulfilIndividual($product, $buyer);

    Enrollment::where('user_id', $buyer->id)->update(['expires_at' => now()->subDay()]);

    Sanctum::actingAs($buyer);

    $this->getJson('/api/v1/my-learning')
        ->assertOk()
        ->assertJsonPath('data.0.expired', true)
        // Still an individual purchase, not relabelled as a company seat.
        ->assertJsonPath('data.0.source', 'purchase')
        ->assertJsonPath('data.0.company_granted', false);
});

it('extends the window when the same buyer purchases again', function (): void {
    [$product, $course] = timedProduct([
        'access_duration_type' => 'fixed_days',
        'access_duration_value' => 30,
    ]);
    $buyer = User::factory()->create();
    fulfilIndividual($product, $buyer);

    Enrollment::where('user_id', $buyer->id)->update(['expires_at' => now()->subDay()]);

    // A renewal: a second paid order for the same product.
    fulfilIndividual($product, $buyer);

    $enrollment = Enrollment::where('user_id', $buyer->id)->where('course_id', $course->id)->firstOrFail();

    expect($enrollment->hasExpired())->toBeFalse()
        ->and($enrollment->expires_at->toDateString())->toBe(now()->addDays(30)->toDateString());
});
