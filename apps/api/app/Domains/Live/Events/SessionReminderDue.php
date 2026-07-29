<?php

namespace App\Domains\Live\Events;

use App\Domains\Live\Models\LiveSession;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A scheduled session reminder has come due (H9). Live emits it; the Notifications consumer delivers
 * it to the session's registered participants (Live never calls the notification pipeline directly —
 * it publishes an event, exactly like SessionScheduled). Carries the reminder id so the consumer can
 * de-duplicate per (reminder, recipient).
 */
class SessionReminderDue
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LiveSession $session,
        public readonly int $reminderId,
    ) {}
}
