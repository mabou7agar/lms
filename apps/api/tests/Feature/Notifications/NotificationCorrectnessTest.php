<?php

use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Notifications\Channels\ChannelManager;
use App\Platform\Notifications\Enums\Channel;
use App\Platform\Notifications\Enums\DeliveryStatus;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Jobs\DeliverNotificationJob;
use App\Platform\Notifications\Models\Notification;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Services\NotificationDispatcher;
use App\Platform\Notifications\Services\RateLimiterService;
use App\Platform\Notifications\Services\TemplateRenderer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
});

function dispatcher(): NotificationDispatcher
{
    return app(NotificationDispatcher::class);
}

function dispatchOnce(int $userId, array $data = [], ?array $channels = null, ?string $dedupKey = null): Notification
{
    return dispatcher()->dispatchToUserId(
        $userId,
        NotificationCategory::System,
        'test_template',
        $data,
        $channels,
        $dedupKey,
    );
}

function configureMailgun(): void
{
    config()->set('notifications.providers.mail', 'mailgun');
    config()->set('services.mailgun', ['domain' => 'mg.test', 'secret' => 'key-x', 'from' => 'no-reply@test']);
}

function configureTwilio(): void
{
    config()->set('notifications.providers.sms', 'twilio');
    config()->set('services.twilio', ['account_sid' => 'AC1', 'auth_token' => 'tok', 'from' => '+1']);
}

// ---------------------------------------------------------------- C3: deduplication

it('does not create a duplicate notification when the same event is dispatched twice', function () {
    Queue::fake();
    $user = User::factory()->create();

    $first = dispatchOnce($user->id, ['x' => 1], [Channel::InApp]);
    $second = dispatchOnce($user->id, ['x' => 1], [Channel::InApp]);

    // Same logical event → one notification, one in-app delivery, one queued job.
    expect($second->id)->toBe($first->id)
        ->and(Notification::count())->toBe(1)
        ->and(NotificationDelivery::where('notification_id', $first->id)->count())->toBe(1);

    Queue::assertPushed(DeliverNotificationJob::class, 1);
});

it('dedups on an explicit caller-supplied event key regardless of payload', function () {
    Queue::fake();
    $user = User::factory()->create();

    dispatchOnce($user->id, ['attempt' => 1], [Channel::InApp], dedupKey: 'course-completed:42');
    dispatchOnce($user->id, ['attempt' => 2], [Channel::InApp], dedupKey: 'course-completed:42');

    expect(Notification::count())->toBe(1);
});

it('treats a different payload as a different notification', function () {
    Queue::fake();
    $user = User::factory()->create();

    dispatchOnce($user->id, ['x' => 1], [Channel::InApp]);
    dispatchOnce($user->id, ['x' => 2], [Channel::InApp]);

    expect(Notification::count())->toBe(2);
});

it('enforces the unique dedup key at the database level', function () {
    $user = User::factory()->create();
    Notification::create(['user_id' => $user->id, 'category' => 'system', 'type' => 't', 'title' => 'a', 'dedup_key' => 'dup']);

    Notification::create(['user_id' => $user->id, 'category' => 'system', 'type' => 't', 'title' => 'b', 'dedup_key' => 'dup']);
})->throws(UniqueConstraintViolationException::class);

it('leaves legacy null dedup keys non-conflicting', function () {
    $user = User::factory()->create();

    // Multiple NULL dedup keys must coexist (Postgres treats NULLs as distinct) — no backfill needed.
    Notification::create(['user_id' => $user->id, 'category' => 'system', 'type' => 't', 'title' => 'a']);
    Notification::create(['user_id' => $user->id, 'category' => 'system', 'type' => 't', 'title' => 'b']);

    expect(Notification::whereNull('dedup_key')->count())->toBe(2);
});

// ---------------------------------------------------------------- C2: rate-limited retries

it('re-queues a rate-limited delivery instead of dead-lettering it', function () {
    Queue::fake();
    $user = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $user->id]);
    $delivery = NotificationDelivery::create([
        'notification_id' => $notification->id, 'channel' => 'in_app', 'status' => DeliveryStatus::Pending->value,
    ]);

    // Exhaust the per-user window so the next check is refused.
    $max = (int) config('notifications.rate_limit.per_minute', 30);
    $limiter = app(RateLimiterService::class);
    for ($i = 0; $i < $max; $i++) {
        $limiter->allow($user->id);
    }

    (new DeliverNotificationJob($delivery->id))->handle(app(ChannelManager::class), app(TemplateRenderer::class), $limiter);

    // Deferred, not buried: a fresh job is queued and the delivery is untouched — no attempt burned.
    Queue::assertPushed(DeliverNotificationJob::class);
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->fresh()->attempts)->toBe(0);
});

// ---------------------------------------------------------------- C2/claim: idempotency

it('does not re-process a delivery that already reached a terminal state', function () {
    $notification = Notification::factory()->create();
    $delivery = NotificationDelivery::create([
        'notification_id' => $notification->id, 'channel' => 'in_app',
        'status' => DeliveryStatus::Sent->value, 'sent_at' => now(),
    ]);

    (new DeliverNotificationJob($delivery->id))->handle(app(ChannelManager::class), app(TemplateRenderer::class), app(RateLimiterService::class));

    expect($delivery->fresh()->attempts)->toBe(0); // untouched
});

it('delivers an in-app notification exactly once and marks it Sent', function () {
    $notification = Notification::factory()->create();
    $delivery = NotificationDelivery::create([
        'notification_id' => $notification->id, 'channel' => 'in_app', 'status' => DeliveryStatus::Pending->value,
    ]);

    $run = fn () => (new DeliverNotificationJob($delivery->id))
        ->handle(app(ChannelManager::class), app(TemplateRenderer::class), app(RateLimiterService::class));

    $run();
    $run(); // a second execution (e.g. after a restart) must be a no-op — already Sent

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent)
        ->and($delivery->fresh()->attempts)->toBe(1);
});

// ---------------------------------------------------------------- H6: truthful channel status

it('records a required channel with no provider as Failed (Configuration), never Sent', function (string $channel) {
    Queue::fake();
    $user = User::factory()->create();

    $notification = dispatchOnce($user->id, [], [Channel::from($channel)]);
    $delivery = $notification->deliveries()->where('channel', $channel)->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::FailedConfiguration);
    Queue::assertNotPushed(DeliverNotificationJob::class);
})->with(['email', 'sms']);

it('records an optional channel with no provider as Skipped (Disabled), never Sent', function (string $channel) {
    Queue::fake();
    $user = User::factory()->create();

    $notification = dispatchOnce($user->id, [], [Channel::from($channel)]);
    $delivery = $notification->deliveries()->where('channel', $channel)->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::SkippedDisabled);
    Queue::assertNotPushed(DeliverNotificationJob::class);
})->with(['whatsapp', 'push']);

it('always records Webhooks as Skipped (Disabled) and never sends (ADR-16)', function () {
    Queue::fake();
    $user = User::factory()->create();

    $notification = dispatchOnce($user->id, [], [Channel::Webhooks]);
    $delivery = $notification->deliveries()->where('channel', 'webhooks')->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::SkippedDisabled);
    Queue::assertNotPushed(DeliverNotificationJob::class);
});

it('records a channel turned off in config as Skipped (Disabled)', function () {
    Queue::fake();
    config()->set('notifications.channels.push.enabled', false);
    $user = User::factory()->create();

    $notification = dispatchOnce($user->id, [], [Channel::Push]);

    expect($notification->deliveries()->where('channel', 'push')->firstOrFail()->status)
        ->toBe(DeliveryStatus::SkippedDisabled);
});

it('queues a configured email channel for a real send', function () {
    Queue::fake();
    configureMailgun();
    $user = User::factory()->create();

    $notification = dispatchOnce($user->id, [], [Channel::Email]);
    $delivery = $notification->deliveries()->where('channel', 'email')->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Pending);
    Queue::assertPushed(DeliverNotificationJob::class, 1);
});

it('queues a configured sms channel for a real send', function () {
    Queue::fake();
    configureTwilio();
    $user = User::factory()->create();

    $notification = dispatchOnce($user->id, [], [Channel::Sms]);

    expect($notification->deliveries()->where('channel', 'sms')->firstOrFail()->status)
        ->toBe(DeliveryStatus::Pending);
    Queue::assertPushed(DeliverNotificationJob::class, 1);
});

it('never reports a fake-provider channel as Sent', function () {
    Queue::fake();
    $user = User::factory()->create();

    // Providers default to fake — email/sms must be Failed(Configuration), not Sent.
    $notification = dispatchOnce($user->id, [], [Channel::Email, Channel::Sms, Channel::Push, Channel::WhatsApp]);

    expect($notification->deliveries()->where('status', DeliveryStatus::Sent->value)->count())->toBe(0);
});
