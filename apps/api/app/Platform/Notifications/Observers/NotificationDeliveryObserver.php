<?php

namespace App\Platform\Notifications\Observers;

use App\Platform\Notifications\Enums\DeliveryStatus;
use App\Platform\Notifications\Events\NotificationFailed;
use App\Platform\Notifications\Events\NotificationQueued;
use App\Platform\Notifications\Events\NotificationSkipped;
use App\Platform\Notifications\Models\NotificationDelivery;

/**
 * Observes NotificationDelivery persistence and emits lifecycle events WITHOUT touching the Sprint 3
 * delivery path. It reads the status the pipeline already wrote and reports it:
 *
 *   created  → Queued (Pending), Skipped (SkippedDisabled) or Failed/configuration (FailedConfiguration).
 *   updated  → Failed/retry when a claimed delivery is returned to Pending for another attempt.
 *
 * It deliberately does NOT emit on the Sent or Dead transitions: DeliverNotificationJob already
 * dispatches NotificationDelivered and NotificationDeadLettered for those, and re-emitting here
 * would duplicate the lifecycle event. The atomic claim (Pending→Processing) is a query-builder
 * update that fires no Eloquent event; "processing" is therefore not separately evented (see the
 * telemetry subscriber notes) rather than reaching into the claim path.
 */
class NotificationDeliveryObserver
{
    public function created(NotificationDelivery $delivery): void
    {
        match ($delivery->status) {
            DeliveryStatus::Pending => NotificationQueued::dispatch($delivery),
            DeliveryStatus::SkippedDisabled => NotificationSkipped::dispatch($delivery),
            DeliveryStatus::FailedConfiguration => NotificationFailed::dispatch($delivery, 'configuration', false),
            default => null, // no other status is created directly
        };
    }

    public function updated(NotificationDelivery $delivery): void
    {
        if (! $delivery->wasChanged('status')) {
            return;
        }

        $original = $delivery->getOriginal('status');
        $originalValue = $original instanceof DeliveryStatus ? $original->value : $original;

        // A claimed delivery (Processing) reset to Pending is the Sprint 3 retry path: a real attempt
        // threw and will be retried. This is the only update transition we report — Sent and Dead are
        // already covered by the job's NotificationDelivered / NotificationDeadLettered events.
        if ($originalValue === DeliveryStatus::Processing->value && $delivery->status === DeliveryStatus::Pending) {
            NotificationFailed::dispatch($delivery, 'delivery_error', true);
        }
    }
}
