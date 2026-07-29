<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Notifications\Channels\ChannelManager;
use App\Platform\Notifications\Enums\Channel;
use App\Platform\Notifications\Enums\DeliveryStatus;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Events\NotificationDeadLettered;
use App\Platform\Notifications\Events\NotificationDelivered;
use App\Platform\Notifications\Events\NotificationFailed;
use App\Platform\Notifications\Events\NotificationQueued;
use App\Platform\Notifications\Events\NotificationSkipped;
use App\Platform\Notifications\Jobs\DeliverNotificationJob;
use App\Platform\Notifications\Models\Notification;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Services\NotificationDispatcher;
use App\Platform\Notifications\Services\RateLimiterService;
use App\Platform\Notifications\Services\TemplateRenderer;
use App\Platform\Notifications\Support\NotificationLogContext;
use App\Platform\Notifications\Support\NotificationMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Sprint 4 — Notification Observability (H5/M2/M3). These pin that the pipeline is now fully
 * observable (lifecycle events, structured logs, idempotent metrics, dead-letter visibility) WITHOUT
 * any change to delivery behavior: every event is derived from the status the Sprint 3 pipeline
 * already wrote.
 */
beforeEach(function () {
    $this->seed(IdentitySeeder::class);
    Cache::flush();
    Context::flush();
});

function obsDispatch(int $userId, array $channels): Notification
{
    return app(NotificationDispatcher::class)->dispatchToUserId(
        $userId,
        NotificationCategory::System,
        'test_template',
        [],
        $channels,
    );
}

function obsDeliverInApp(Notification $notification): NotificationDelivery
{
    $delivery = NotificationDelivery::create([
        'notification_id' => $notification->id,
        'channel' => 'in_app',
        'status' => DeliveryStatus::Pending->value,
    ]);

    (new DeliverNotificationJob($delivery->id))
        ->handle(app(ChannelManager::class), app(TemplateRenderer::class), app(RateLimiterService::class));

    return $delivery->fresh();
}

function metrics(): NotificationMetrics
{
    return app(NotificationMetrics::class);
}

// ---------------------------------------------------------------- lifecycle events

it('emits NotificationQueued once when an available channel is queued', function () {
    Event::fake([NotificationQueued::class, NotificationSkipped::class, NotificationFailed::class]);
    Queue::fake();
    $user = User::factory()->create();

    obsDispatch($user->id, [Channel::InApp]);

    Event::assertDispatched(NotificationQueued::class, 1);
    Event::assertNotDispatched(NotificationSkipped::class);
    Event::assertNotDispatched(NotificationFailed::class);
});

it('emits NotificationSkipped for a disabled/optional channel', function () {
    Event::fake([NotificationSkipped::class]);
    $user = User::factory()->create();

    // Push is optional and unconfigured (fake provider) → recorded Skipped (Disabled), never queued.
    obsDispatch($user->id, [Channel::Push]);

    Event::assertDispatched(NotificationSkipped::class, 1);
});

it('emits NotificationFailed(configuration) for a required unconfigured channel', function () {
    Event::fake([NotificationFailed::class]);
    $user = User::factory()->create();

    // Email is required but has only the fake provider → Failed (Configuration).
    obsDispatch($user->id, [Channel::Email]);

    Event::assertDispatched(
        NotificationFailed::class,
        fn (NotificationFailed $e): bool => $e->reason === 'configuration' && $e->retriable === false,
    );
});

it('does not emit duplicate lifecycle events for a deduplicated notification', function () {
    Event::fake([NotificationQueued::class]);
    Queue::fake();
    $user = User::factory()->create();

    obsDispatch($user->id, [Channel::InApp]);
    obsDispatch($user->id, [Channel::InApp]); // same event → deduped, no new delivery

    Event::assertDispatched(NotificationQueued::class, 1);
});

it('emits lifecycle events in order: queued then sent', function () {
    $sequence = [];
    Event::listen(NotificationQueued::class, function () use (&$sequence): void {
        $sequence[] = 'queued';
    });
    Event::listen(NotificationDelivered::class, function () use (&$sequence): void {
        $sequence[] = 'sent';
    });

    $user = User::factory()->create();
    obsDeliverInApp(Notification::factory()->create(['user_id' => $user->id]));

    expect($sequence)->toBe(['queued', 'sent']);
});

it('emits a dead-letter event when a delivery exhausts its attempts', function () {
    Event::fake([NotificationDeadLettered::class]);
    $user = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $user->id]);

    $delivery = NotificationDelivery::create([
        'notification_id' => $notification->id, 'channel' => 'in_app', 'status' => DeliveryStatus::Processing->value,
    ]);

    // A job that reaches failed() has genuinely exhausted its retries — a real dead-letter.
    (new DeliverNotificationJob($delivery->id))->failed(new RuntimeException('smtp down'));

    Event::assertDispatched(NotificationDeadLettered::class, 1);
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Dead);
});

it('emits a retriable NotificationFailed when a claimed delivery is reset for retry', function () {
    Event::fake([NotificationFailed::class]);
    $user = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $user->id]);

    $delivery = NotificationDelivery::create([
        'notification_id' => $notification->id, 'channel' => 'in_app', 'status' => DeliveryStatus::Pending->value,
    ]);
    $delivery->forceFill(['status' => DeliveryStatus::Processing->value])->save(); // claim (no event)
    $delivery->forceFill(['status' => DeliveryStatus::Pending->value])->save();     // retry reset

    Event::assertDispatched(
        NotificationFailed::class,
        fn (NotificationFailed $e): bool => $e->retriable === true && $e->reason === 'delivery_error',
    );
});

// ---------------------------------------------------------------- metrics

it('counts queued and sent deliveries per channel', function () {
    $user = User::factory()->create();

    obsDeliverInApp(Notification::factory()->create(['user_id' => $user->id]));

    expect(metrics()->count('queued', 'in_app'))->toBe(1)
        ->and(metrics()->count('sent', 'in_app'))->toBe(1)
        ->and(metrics()->count('sent'))->toBe(1); // global counter too
});

it('keeps metric counters idempotent when an event is re-processed', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $user->id]);
    $delivery = NotificationDelivery::create([
        'notification_id' => $notification->id, 'channel' => 'in_app',
        'status' => DeliveryStatus::Sent->value, 'sent_at' => now(),
    ]);

    // The same terminal event re-fired (e.g. after a worker restart) must not double-count.
    event(new NotificationDelivered($delivery));
    event(new NotificationDelivered($delivery));

    expect(metrics()->count('sent', 'in_app'))->toBe(1);
});

it('records a retry metric for each attempt', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $user->id]);
    $delivery = NotificationDelivery::create([
        'notification_id' => $notification->id, 'channel' => 'in_app', 'status' => DeliveryStatus::Pending->value,
    ]);

    $delivery->forceFill(['status' => DeliveryStatus::Processing->value, 'attempts' => 1])->save();
    $delivery->forceFill(['status' => DeliveryStatus::Pending->value])->save(); // retry after attempt 1

    expect(metrics()->count('retries', 'in_app'))->toBe(1);
});

it('accumulates delivery latency on send', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $user->id]);
    // Backdate the notification so the queued→sent latency is measurable.
    DB::table('notifications')->where('id', $notification->id)->update(['created_at' => now()->subSeconds(3)]);

    obsDeliverInApp($notification->fresh());

    expect(metrics()->latencyCount())->toBe(1)
        ->and(metrics()->averageLatencyMs())->toBeGreaterThan(0.0);
});

// ---------------------------------------------------------------- structured logging (M3)

it('logs a structured, metadata-only line on send and never logs content', function () {
    Log::spy();
    $user = User::factory()->create();

    obsDeliverInApp(Notification::factory()->create(['user_id' => $user->id, 'title' => 'SECRET SUBJECT']));

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        return $message === 'notification.sent'
            && array_key_exists('notification_id', $context)
            && array_key_exists('channel', $context)
            && array_key_exists('delivery_status', $context)
            && array_key_exists('attempts', $context)
            && ! array_key_exists('title', $context)   // content is never logged
            && ! array_key_exists('body', $context)
            && ! array_key_exists('data', $context);
    })->once();
});

it('records dead-letter reason, retry count, final status and timestamp', function () {
    Log::spy();
    $user = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $user->id]);
    $delivery = NotificationDelivery::create([
        'notification_id' => $notification->id, 'channel' => 'in_app',
        'status' => DeliveryStatus::Processing->value, 'attempts' => 3,
    ]);

    (new DeliverNotificationJob($delivery->id))->failed(new RuntimeException('provider exploded'));

    Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
        return $message === 'notification.dead_letter'
            && ($context['reason'] ?? null) === 'provider exploded'
            && ($context['retry_count'] ?? null) === 3
            && ($context['final_status'] ?? null) === 'dead'
            && ! empty($context['dead_at']);
    })->once();

    expect(metrics()->count('dead_letter', 'in_app'))->toBe(1);
});

it('builds a metadata-only log context with the required fields', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $user->id, 'title' => 'PRIVATE', 'body' => 'PRIVATE BODY']);
    $delivery = NotificationDelivery::create([
        'notification_id' => $notification->id, 'channel' => 'in_app', 'status' => DeliveryStatus::Pending->value,
    ]);

    $context = NotificationLogContext::for($delivery);

    expect($context)->toHaveKeys(['notification_id', 'channel', 'delivery_status', 'attempts', 'user_id'])
        ->and($context)->not->toHaveKey('title')
        ->and($context)->not->toHaveKey('body')
        ->and($context['user_id'])->toBe($user->id);
});

// ---------------------------------------------------------------- correlation id (M2)

it('captures the correlation id into the propagating Context', function () {
    $middleware = new AssignCorrelationId;
    $request = Request::create('/api/v1/health', 'GET');
    $request->headers->set(AssignCorrelationId::HEADER, 'cid-from-request');

    $middleware->handle($request, fn (): Response => new Response('ok'));

    // Context is what Laravel serializes into queued jobs, so this is the value a worker log carries.
    expect(Context::get('correlation_id'))->toBe('cid-from-request');
});

it('surfaces the propagated correlation id in the notification log context', function () {
    Context::add('correlation_id', 'cid-xyz');
    $user = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $user->id]);
    $delivery = NotificationDelivery::create([
        'notification_id' => $notification->id, 'channel' => 'in_app', 'status' => DeliveryStatus::Pending->value,
    ]);

    expect(NotificationLogContext::for($delivery)['correlation_id'])->toBe('cid-xyz');
});
