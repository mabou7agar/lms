<?php

namespace App\Domains\Live\Jobs;

use App\Domains\Live\Enums\ReminderStatus;
use App\Domains\Live\Events\SessionReminderDue;
use App\Domains\Live\Models\SessionReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one due session reminder (H9). Publishes SessionReminderDue for the Notifications consumer
 * to fan out to registered participants, then marks the reminder Sent.
 *
 * Duplicate protection is layered:
 *   - the event is emitted BEFORE the terminal write, and the consumer de-duplicates per
 *     (reminder, recipient) on the notification dedup key, so a retry — or two concurrent runs —
 *     never produces a second notification;
 *   - a reminder already Sent/Cancelled is skipped, so no redundant work is done.
 * This ordering is deliberately fail-safe: a crash after emit but before the Sent write simply
 * re-emits on retry (harmless, de-duplicated) rather than silently dropping the reminder.
 */
class DispatchSessionReminderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Hard ceiling on a single run so a stuck worker is reclaimed rather than hanging forever. */
    public int $timeout = 60;

    public function __construct(public readonly int $reminderId) {}

    public function tries(): int
    {
        return (int) config('notifications.retry.max_attempts', 3);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return (array) config('notifications.retry.backoff_seconds', [10, 60, 300]);
    }

    public function handle(): void
    {
        $reminder = SessionReminder::with('session')->find($this->reminderId);

        // Gone, already delivered, or cancelled — nothing to do (idempotent).
        if ($reminder === null || $reminder->status !== ReminderStatus::Pending) {
            return;
        }

        $session = $reminder->session;
        if ($session === null) {
            $reminder->forceFill(['status' => ReminderStatus::Cancelled->value])->save();

            return;
        }

        SessionReminderDue::dispatch($session, (int) $reminder->getKey());

        $reminder->forceFill(['status' => ReminderStatus::Sent->value])->save();

        Log::info('live.reminder.dispatched', [
            'reminder_id' => (int) $reminder->getKey(),
            'session_id' => (int) $session->getKey(),
            'offset_minutes' => $reminder->offset_minutes,
        ]);
    }

    /**
     * All retries exhausted (or the worker timed out). Retire the reminder so it is not left
     * Pending forever, and emit a dead-letter log line so the failure is observable rather than
     * silent. Emitting the event before the Sent write means a genuinely-delivered reminder that
     * failed only on the terminal write is still de-duplicated by the consumer.
     */
    public function failed(Throwable $e): void
    {
        $reminder = SessionReminder::find($this->reminderId);

        if ($reminder !== null && $reminder->status === ReminderStatus::Pending) {
            $reminder->forceFill(['status' => ReminderStatus::Cancelled->value])->save();
        }

        Log::error('live.reminder.failed', [
            'reminder_id' => $this->reminderId,
            'error' => $e->getMessage(),
        ]);
    }
}
