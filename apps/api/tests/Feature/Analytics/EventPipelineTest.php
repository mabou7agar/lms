<?php

declare(strict_types=1);

use App\Contexts\Analytics\Models\AnalyticsEvent;
use App\Contexts\Commerce\Actions\Payment\FulfillOrderAction;
use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\OrderItem;
use App\Contexts\Commerce\Models\Product;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Analytics\AnalyticsEventName;
use App\Platform\Shared\Analytics\Contracts\AnalyticsEventRecorder;
use App\Platform\Shared\Analytics\Data\AnalyticsEventInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Commerce/CommerceHelpers.php';

function paidOrderForEvents(Product $product, User $buyer): Order
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

// ── The recorder itself ──────────────────────────────────────────────────────────────────────────

it('writes an event with its dimensions', function (): void {
    app(AnalyticsEventRecorder::class)->record(new AnalyticsEventInput(
        name: AnalyticsEventName::CourseViewed->value,
        userId: 7,
        courseId: 3,
        sessionId: 'sess-abc',
        utmSource: 'newsletter',
    ));

    $event = AnalyticsEvent::firstOrFail();

    expect($event->name)->toBe('course_viewed')
        ->and($event->user_id)->toBe(7)
        ->and($event->course_id)->toBe(3)
        ->and($event->session_id)->toBe('sess-abc')
        ->and($event->occurred_at)->not->toBeNull();
});

it('records a repeated fact once when it carries a dedup key', function (): void {
    $recorder = app(AnalyticsEventRecorder::class);

    $event = fn (): AnalyticsEventInput => new AnalyticsEventInput(
        name: AnalyticsEventName::OrderPaid->value,
        orderId: 42,
        dedupKey: 'order_paid:42',
    );

    $recorder->record($event());
    $recorder->record($event());
    $recorder->record($event());

    expect(AnalyticsEvent::named(AnalyticsEventName::OrderPaid)->count())->toBe(1);
});

it('keeps repeat occurrences that are genuinely separate', function (): void {
    $recorder = app(AnalyticsEventRecorder::class);

    // No dedup key: two downloads of the same file are two downloads.
    $recorder->record(new AnalyticsEventInput(name: AnalyticsEventName::FileDownloaded->value, userId: 1, courseId: 2));
    $recorder->record(new AnalyticsEventInput(name: AnalyticsEventName::FileDownloaded->value, userId: 1, courseId: 2));

    expect(AnalyticsEvent::named(AnalyticsEventName::FileDownloaded)->count())->toBe(2);
});

it('refuses a name outside the vocabulary rather than storing it', function (): void {
    app(AnalyticsEventRecorder::class)->record(new AnalyticsEventInput(name: 'totally_made_up'));

    expect(AnalyticsEvent::count())->toBe(0);
});

it('never lets a recording failure reach the caller', function (): void {
    // The table is gone; a business flow that records an event must still complete.
    Schema::drop('analytics_events');

    app(AnalyticsEventRecorder::class)->record(new AnalyticsEventInput(
        name: AnalyticsEventName::CourseViewed->value,
    ));

    expect(true)->toBeTrue(); // reaching here at all is the assertion
});

// ── Business flows write their events ────────────────────────────────────────────────────────────

it('records the sale when an order is fulfilled', function (): void {
    [, $product] = courseProduct(19900);
    $buyer = User::factory()->create();

    $order = paidOrderForEvents($product, $buyer);

    $event = AnalyticsEvent::named(AnalyticsEventName::OrderPaid)->firstOrFail();

    expect($event->order_id)->toBe((int) $order->id)
        ->and($event->user_id)->toBe((int) $buyer->id)
        ->and($event->buyer_type)->toBe('individual')
        ->and($event->value_minor)->toBe(19900);
});

it('does not record a second sale when fulfilment runs again', function (): void {
    [, $product] = courseProduct(19900);
    $order = paidOrderForEvents($product, User::factory()->create());

    $order->forceFill(['fulfilled_at' => null])->save();
    app(FulfillOrderAction::class)->execute($order);

    expect(AnalyticsEvent::named(AnalyticsEventName::OrderPaid)->count())->toBe(1);
});

it('records adding a product to the cart', function (): void {
    [, $product] = courseProduct(19900);
    $product->forceFill(['audience' => 'both'])->save();
    $buyer = User::factory()->create();

    Sanctum::actingAs($buyer);
    $this->postJson('/api/v1/cart', ['product' => $product->public_id])->assertOk();

    $event = AnalyticsEvent::named(AnalyticsEventName::CartItemAdded)->firstOrFail();

    expect($event->product_id)->toBe((int) $product->id)
        ->and($event->user_id)->toBe((int) $buyer->id);
});

// ── The client collector ─────────────────────────────────────────────────────────────────────────

it('accepts a browser reporting what only it can see', function (): void {
    $course = Course::factory()->published()->create();

    $this->postJson('/api/v1/analytics/events', [
        'events' => [
            ['name' => 'course_viewed', 'course_id' => $course->public_id, 'session_id' => 's1', 'utm_source' => 'twitter'],
            ['name' => 'search_performed', 'term' => 'project management', 'session_id' => 's1'],
        ],
    ])->assertStatus(202);

    expect(AnalyticsEvent::count())->toBe(2);

    $view = AnalyticsEvent::named(AnalyticsEventName::CourseViewed)->firstOrFail();
    expect($view->course_id)->toBe((int) $course->id)
        ->and($view->utm_source)->toBe('twitter')
        // Anonymous: the top of the funnel has no user, and that is the point.
        ->and($view->user_id)->toBeNull();

    expect(AnalyticsEvent::named(AnalyticsEventName::SearchPerformed)->firstOrFail()->metadata['term'])
        ->toBe('project management');
});

it('refuses to let a browser claim a server-authoritative event', function (): void {
    // A client that could post order_paid could invent revenue.
    $this->postJson('/api/v1/analytics/events', [
        'events' => [['name' => 'order_paid']],
    ])->assertStatus(422);

    expect(AnalyticsEvent::count())->toBe(0);
});

it('attributes a reported event to the signed-in user, never to a claimed one', function (): void {
    $user = User::factory()->create();
    $someoneElse = User::factory()->create();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/analytics/events', [
        'events' => [['name' => 'cta_clicked', 'label' => 'buy_now']],
        // A payload trying to attribute the activity to somebody else is simply not read.
        'user_id' => $someoneElse->id,
    ])->assertStatus(202);

    expect(AnalyticsEvent::firstOrFail()->user_id)->toBe((int) $user->id);
});
