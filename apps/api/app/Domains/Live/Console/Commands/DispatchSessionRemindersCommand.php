<?php

namespace App\Domains\Live\Console\Commands;

use App\Domains\Live\Enums\ReminderStatus;
use App\Domains\Live\Jobs\DispatchSessionReminderJob;
use App\Domains\Live\Models\SessionReminder;
use Illuminate\Console\Command;

/**
 * H9 — the scheduled consumer that was missing. Finds session reminders that have come due and are
 * still pending, and queues one delivery job each. It only ENQUEUES (bounded, fast); the job does the
 * delivery and the terminal write, so this command is safe to run every minute.
 */
class DispatchSessionRemindersCommand extends Command
{
    protected $signature = 'live:dispatch-reminders';

    protected $description = 'Queue delivery of session reminders that have come due';

    public function handle(): int
    {
        $dueIds = SessionReminder::query()
            ->where('status', ReminderStatus::Pending->value)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->pluck('id');

        foreach ($dueIds as $id) {
            DispatchSessionReminderJob::dispatch((int) $id)
                ->onQueue((string) config('notifications.queue', 'notifications'));
        }

        $this->info($dueIds->count().' due reminder(s) queued.');

        return self::SUCCESS;
    }
}
