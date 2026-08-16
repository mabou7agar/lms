<?php

namespace App\Contexts\Commerce\Console\Commands;

use App\Contexts\Commerce\Services\ExpiryReminderService;
use Illuminate\Console\Command;

/**
 * Warns people before something they paid for runs out: a company's purchased training, an
 * employee's seat access, and a learner's certificate.
 *
 * Scheduled daily rather than by the minute because these are day-granularity notices — nobody needs
 * "your access expires in 30 days" delivered at a precise moment. Safe to run by hand or twice in a
 * day: every notice is deduplicated on (kind, reference, recipient, offset), so a repeat run sends
 * nothing new.
 */
class SendExpiryRemindersCommand extends Command
{
    protected $signature = 'commerce:send-expiry-reminders';

    protected $description = 'Notify buyers, employees and learners about purchases, seats and certificates that are about to expire.';

    public function handle(ExpiryReminderService $reminders): int
    {
        $sent = $reminders->sweep();

        $this->info(sprintf(
            'Expiry reminders: %d purchase, %d seat access, %d certificate.',
            $sent['purchases'],
            $sent['seats'],
            $sent['certificates'],
        ));

        return self::SUCCESS;
    }
}
