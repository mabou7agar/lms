<?php

namespace App\Platform\Notifications\Events;

use App\Platform\Notifications\Models\NotificationDelivery;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Observability-only lifecycle event: a disabled/optional-unconfigured channel was recorded with a
 * terminal SkippedDisabled status and never queued (H6 truthfulness). Dispatched once per delivery
 * by the NotificationDelivery observer; it reports the outcome and changes nothing.
 */
class NotificationSkipped
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly NotificationDelivery $delivery) {}
}
