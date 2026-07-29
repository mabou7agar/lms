<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Notifications\Enums\Channel;
use App\Platform\Notifications\Enums\ChannelAvailability;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Jobs\DeliverNotificationJob;
use App\Platform\Notifications\Models\Notification;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Models\UserNotificationSetting;
use App\Platform\Shared\Services\BaseService;

/**
 * Creates a notification and its per-channel delivery rows, then queues the deliverable ones.
 *
 * Two correctness guarantees this class is responsible for:
 *
 *   Deduplication (C3): the notification is keyed by a DETERMINISTIC dedup key — a caller-supplied
 *   event key, or a hash of (recipient, category, template, payload). A unique index on that key
 *   makes re-dispatching the same domain event return the existing notification instead of creating
 *   a second one and sending everything twice. The old key embedded now()->format('YmdHi'), so it
 *   changed every minute and never actually deduplicated.
 *
 *   Truthful status (H6): each channel's availability is resolved BEFORE queueing. An available
 *   channel gets a Pending delivery and a queued job; a disabled/misconfigured channel gets a
 *   terminal Skipped/Failed status recorded immediately and is never queued — so the ledger can
 *   never claim a disabled channel was Sent.
 */
class NotificationDispatcher extends BaseService
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly PreferenceService $preferences,
        private readonly ChannelAvailabilityResolver $availability,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, Channel>|null  $channels
     */
    public function dispatchToUserId(int $userId, NotificationCategory $category, string $templateKey, array $data = [], ?array $channels = null, ?string $dedupKey = null): Notification
    {
        $locale = $this->localeForUserId($userId);
        $inApp = $this->renderer->render($templateKey, Channel::InApp, $locale, $data);

        $candidate = $channels ?? $this->defaultChannels();
        $enabled = $this->preferences->enabledChannelsForUserId($userId, $category, $candidate);
        $key = $this->deterministicDedupKey($dedupKey, $userId, $category->value, $templateKey, $data);

        return $this->transaction(function () use ($userId, $category, $templateKey, $data, $locale, $inApp, $enabled, $key): Notification {
            // firstOrCreate on the unique dedup key: a concurrent or repeated dispatch of the same
            // event resolves to the one existing row (the losing insert hits the unique index and is
            // re-read), so we never create a second notification or a second set of deliveries.
            $notification = Notification::firstOrCreate(
                ['dedup_key' => $key],
                [
                    'user_id' => $userId,
                    'category' => $category->value,
                    'type' => $templateKey,
                    'title' => $inApp->subject,
                    'body' => $inApp->body,
                    'data' => $data,
                    'locale' => $locale,
                ],
            );

            if (! $notification->wasRecentlyCreated) {
                return $notification; // dedup: deliveries already exist from the first dispatch
            }

            foreach ($enabled as $channel) {
                $availability = $this->availability->for($channel);

                $delivery = NotificationDelivery::create([
                    'notification_id' => $notification->id,
                    'channel' => $channel->value,
                    'status' => $availability->terminalStatus()->value,
                ]);

                // Only an available channel is queued for a real send. Disabled/misconfigured
                // channels already carry their truthful terminal status and are never attempted.
                if ($availability === ChannelAvailability::Available) {
                    DeliverNotificationJob::dispatch($delivery->id)
                        ->onQueue((string) config('notifications.queue', 'notifications'));
                }
            }

            return $notification;
        });
    }

    /**
     * A stable identity for "the same notification". A caller with a natural event key (e.g.
     * "course-completed:{enrollmentId}") should pass it; otherwise it is derived from the recipient,
     * category, template and payload. NEVER time-based — the whole point is that a retry produces the
     * same key. Callers that legitimately repeat the same template to the same user with identical
     * payload must pass a distinguishing dedup key.
     *
     * @param  array<string, mixed>  $data
     */
    private function deterministicDedupKey(?string $explicit, int $userId, string $category, string $templateKey, array $data): string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        ksort($data);

        return 'auto:'.hash('sha256', $userId.'|'.$category.'|'.$templateKey.'|'.(string) json_encode($data));
    }

    /** @return array<int, Channel> */
    private function defaultChannels(): array
    {
        return array_map(fn (string $c) => Channel::from($c), (array) config('notifications.default_channels', ['in_app']));
    }

    private function localeForUserId(int $userId): string
    {
        $setting = UserNotificationSetting::where('user_id', $userId)->first();

        return $setting?->locale ?? (string) config('notifications.locale.default', 'en');
    }
}
