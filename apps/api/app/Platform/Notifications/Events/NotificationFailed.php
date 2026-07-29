<?php

namespace App\Platform\Notifications\Events;

use App\Platform\Notifications\Models\NotificationDelivery;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Observability-only lifecycle event: a delivery failed. Two truthful shapes, distinguished by
 * $reason / $retriable:
 *   - reason "configuration", retriable false: a required channel had no working provider and was
 *     recorded FailedConfiguration at creation (never queued).
 *   - reason "delivery_error", retriable true: a real send attempt threw and the delivery was
 *     returned to Pending for a retry (retry visibility).
 *
 * Dispatched once per occurrence by the NotificationDelivery observer. It carries no behavior — the
 * status was already written by the Sprint 3 pipeline; this only reports it.
 */
class NotificationFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly NotificationDelivery $delivery,
        public readonly string $reason,
        public readonly bool $retriable,
    ) {}
}
