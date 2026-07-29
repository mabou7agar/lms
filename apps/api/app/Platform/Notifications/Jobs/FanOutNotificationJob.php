<?php

namespace App\Platform\Notifications\Jobs;

use App\Platform\Notifications\Actions\BulkNotificationAction;
use App\Platform\Notifications\Enums\NotificationCategory;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers one chunk of a notification fan-out (H4). One of these is dispatched per chunk of
 * recipients inside a Bus batch, so a large-cohort announcement is delivered by workers instead of
 * in the HTTP request.
 *
 * Idempotency + retry-safety are inherited, not reinvented: each recipient goes through the Sprint 3
 * NotificationDispatcher, which firstOrCreates the notification on a deterministic dedup key, so
 * re-running this chunk after a retry (or a duplicate dispatch) never creates a second notification.
 */
class FanOutNotificationJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly array $userIds,
        public readonly NotificationCategory $category,
        public readonly string $templateKey,
        public readonly array $data,
    ) {}

    public function tries(): int
    {
        return (int) config('notifications.retry.max_attempts', 3);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return (array) config('notifications.retry.backoff_seconds', [10, 60, 300]);
    }

    public function handle(BulkNotificationAction $action): void
    {
        // If an operator cancelled the batch mid fan-out, stop enqueuing further work.
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $action->executeForUserIds($this->userIds, $this->category, $this->templateKey, $this->data);
    }
}
