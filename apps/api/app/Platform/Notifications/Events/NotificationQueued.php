<?php

namespace App\Platform\Notifications\Events;

use App\Platform\Notifications\Models\NotificationDelivery;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Observability-only lifecycle event: an available channel's delivery row was created with a
 * Pending status and queued for a real send. Dispatched once per delivery by the
 * NotificationDelivery observer — it carries no delivery behavior of its own.
 */
class NotificationQueued
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly NotificationDelivery $delivery) {}
}
