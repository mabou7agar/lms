<?php

namespace App\Platform\Notifications\Actions;

use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Jobs\FanOutNotificationJob;
use App\Platform\Notifications\Services\NotificationDispatcher;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Support\Facades\Bus;

/**
 * Fans a notification out to many users (each delivery is queued independently).
 */
class BulkNotificationAction extends BaseAction
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * Synchronous fan-out — one dispatch per user in the caller's process. Suitable only for a small,
     * bounded recipient set (or as the per-chunk worker of queueForUserIds()).
     *
     * @param  array<int, int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function executeForUserIds(array $userIds, NotificationCategory $category, string $templateKey, array $data = []): int
    {
        $count = 0;
        foreach ($userIds as $userId) {
            $this->dispatcher->dispatchToUserId($userId, $category, $templateKey, $data);
            $count++;
        }

        return $count;
    }

    /**
     * Asynchronous fan-out (H4). Recipients are chunked and each chunk becomes a queued, retry-safe
     * job dispatched as ONE Bus batch — so the caller (an HTTP request) returns immediately while
     * workers deliver, and the batch gives progress + failure tracking. Per-user delivery is
     * identical to executeForUserIds(): the same dispatcher, so the same de-duplication.
     *
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function queueForUserIds(array $userIds, NotificationCategory $category, string $templateKey, array $data = [], ?string $batchName = null): void
    {
        if ($userIds === []) {
            return;
        }

        $chunkSize = max(1, (int) config('notifications.fanout.chunk_size', 500));

        $jobs = array_map(
            fn (array $chunk): FanOutNotificationJob => new FanOutNotificationJob($chunk, $category, $templateKey, $data),
            array_chunk($userIds, $chunkSize),
        );

        Bus::batch($jobs)
            ->name($batchName ?? ('notification-fanout:'.$templateKey))
            ->onQueue((string) config('notifications.queue', 'notifications'))
            ->allowFailures()
            ->dispatch();
    }
}
