<?php

use App\Contexts\Commerce\Events\OrderPaid;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Learning\Events\CourseCompleted;
use App\Contexts\Learning\Events\UserEnrolled;
use App\Contexts\Learning\Models\Enrollment;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Listeners\NotificationEventSubscriber;
use App\Platform\Notifications\Models\Notification;
use App\Platform\Notifications\Services\NotificationDispatcher;
use Mockery\MockInterface;

/**
 * Regression (audit finding D1/Q1-Q3): the notification subscriber must pass an explicit,
 * aggregate-scoped dedup key for every event whose payload is empty or non-distinguishing.
 *
 * Without it the dispatcher's deterministic auto key — sha256(user|category|template|payload) — is
 * constant per (user, template) and the UNIQUE notifications.dedup_key index silently drops every
 * such notification after the user's first (second enrollment/completion never notified; a second
 * order with an equal total gets no receipt). These tests pin the 6th argument (dedupKey) that the
 * fix supplies; they fail against the pre-fix code (which passed no dedup key at all).
 *
 * The dispatcher is used as a recording stub so the assertions are explicit (no reliance on Mockery
 * expectation verification at teardown) and no database/template rendering is required.
 */
function recordDispatch(): array
{
    $calls = [];
    /** @var MockInterface&NotificationDispatcher $dispatcher */
    $dispatcher = Mockery::mock(NotificationDispatcher::class);
    // dispatchToUserId is typed `: Notification` (non-nullable); Mockery enforces the declared
    // return type on the mocked method, so the recording stub must hand back an instance. The
    // subscriber ignores the return value — an unsaved model is enough (no DB touched).
    $dispatcher->shouldReceive('dispatchToUserId')->andReturnUsing(function (...$args) use (&$calls) {
        $calls[] = $args;

        return new Notification;
    });

    // Return recorded calls via a by-reference closure. An arrow fn (fn () => $calls) captures
    // $calls by value at definition time (an empty array) and never sees the stub's later appends.
    return [$dispatcher, function () use (&$calls): array {
        return $calls;
    }];
}

function enrollment(int $id, int $userId): Enrollment
{
    return (new Enrollment)->forceFill(['id' => $id, 'user_id' => $userId]);
}

it('gives each enrollment confirmation a distinct per-enrollment dedup key', function () {
    [$dispatcher, $calls] = recordDispatch();

    (new NotificationEventSubscriber($dispatcher))
        ->onUserEnrolled(new UserEnrolled(enrollment(101, 7)));

    $recorded = $calls();
    expect($recorded)->toHaveCount(1)
        ->and($recorded[0][0])->toBe(7)
        ->and($recorded[0][1])->toBe(NotificationCategory::Learning)
        ->and($recorded[0][2])->toBe('enrollment_confirmed')
        ->and($recorded[0][5])->toBe('enrollment-confirmed:101');
});

it('gives each course completion a distinct per-enrollment dedup key', function () {
    [$dispatcher, $calls] = recordDispatch();

    (new NotificationEventSubscriber($dispatcher))
        ->onCourseCompleted(new CourseCompleted(enrollment(202, 7)));

    $recorded = $calls();
    expect($recorded)->toHaveCount(1)
        ->and($recorded[0][2])->toBe('course_completed')
        ->and($recorded[0][5])->toBe('course-completed:202');
});

it('gives each order receipt a distinct per-order dedup key even when totals collide', function () {
    [$dispatcher, $calls] = recordDispatch();

    $order = (new Order)->forceFill(['id' => 55, 'user_id' => 7, 'total_minor' => 5000]);

    (new NotificationEventSubscriber($dispatcher))
        ->onOrderPaid(new OrderPaid($order));

    $recorded = $calls();
    expect($recorded)->toHaveCount(1)
        ->and($recorded[0][2])->toBe('order_receipt')
        ->and($recorded[0][5])->toBe('order-receipt:55');
});
