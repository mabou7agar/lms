<?php

declare(strict_types=1);

namespace App\Domains\Qna\Console\Commands;

use App\Domains\Qna\Services\OverdueQuestionNotifier;
use Illuminate\Console\Command;

/**
 * Escalates learner questions that have gone unanswered past the response promise.
 *
 * Scheduled daily: this is a nudge, not an alarm, and a team that is told hourly about the same
 * backlog stops reading the channel. Safe to run by hand or twice — every notice is deduplicated on
 * (question, recipient).
 */
class SendOverdueQuestionRemindersCommand extends Command
{
    protected $signature = 'qna:send-overdue-reminders';

    protected $description = 'Notify course teams about learner questions that have breached the response SLA.';

    public function handle(OverdueQuestionNotifier $notifier): int
    {
        $this->info(sprintf('Overdue question reminders: %d sent.', $notifier->sweep()));

        return self::SUCCESS;
    }
}
