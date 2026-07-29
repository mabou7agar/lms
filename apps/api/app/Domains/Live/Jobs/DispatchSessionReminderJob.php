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
}
