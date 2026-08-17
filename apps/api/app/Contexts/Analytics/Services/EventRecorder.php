<?php

namespace App\Contexts\Analytics\Services;

use App\Contexts\Analytics\Models\AnalyticsEvent;
use App\Platform\Shared\Analytics\AnalyticsEventName;
use App\Platform\Shared\Analytics\Contracts\AnalyticsEventRecorder;
use App\Platform\Shared\Analytics\Data\AnalyticsEventInput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Analytics' implementation of the Shared recorder.
 *
 * IT NEVER THROWS. Every write is wrapped, and a failure is logged and dropped. That is not
 * defensiveness for its own sake: this is called from inside checkout, from a file download, from
 * posting an answer — and a reporting table being full, locked or missing must not be able to fail
 * any of them. A missing row in a chart is a nuisance; a failed purchase because a chart wanted a
 * row is a defect.
 *
 * Deduplication is the unique index doing the work: a caller supplies a key derived from the thing
 * that happened, a retry collides, and the collision is swallowed. Callers therefore do not have to
 * reason about whether their code path can run twice.
 *
 * Unknown event names are refused rather than written. The vocabulary is closed so that a typo shows
 * up as a missing chart during development instead of as a silently under-reporting funnel forever.
 */
class EventRecorder implements AnalyticsEventRecorder
{
    public function record(AnalyticsEventInput $event): void
    {
        $this->recordMany([$event]);
    }

    /**
     * @param  list<AnalyticsEventInput>  $events
     */
    public function recordMany(array $events): void
    {
        try {
            $rows = [];

            foreach ($events as $event) {
                $row = $this->toRow($event);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            if ($rows === []) {
                return;
            }

            // insertOrIgnore: a colliding dedup key means "already recorded", which is success, not
            // a conflict to report. Batched so instrumenting a five-course fulfilment costs one
            // round trip rather than five.
            AnalyticsEvent::insertOrIgnore($rows);
        } catch (Throwable $e) {
            // Deliberately swallowed — see the class docblock. Logged so the gap is discoverable.
            Log::warning('analytics.event_record_failed', [
                'error' => $e->getMessage(),
                'names' => array_map(static fn (AnalyticsEventInput $i): string => $i->name, $events),
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null null when the name is not in the vocabulary
     */
    private function toRow(AnalyticsEventInput $event): ?array
    {
        if (AnalyticsEventName::tryFrom($event->name) === null) {
            Log::warning('analytics.unknown_event_name', ['name' => $event->name]);

            return null;
        }

        $now = Carbon::now();
        $occurredAt = $event->occurredAt === null ? $now : Carbon::parse($event->occurredAt);

        return [
            'name' => $event->name,
            'user_id' => $event->userId,
            'organization_id' => $event->organizationId,
            'course_id' => $event->courseId,
            'product_id' => $event->productId,
            'order_id' => $event->orderId,
            'instructor_id' => $event->instructorId,
            'product_type' => $event->productType,
            'buyer_type' => $event->buyerType,
            'utm_source' => $this->trim($event->utmSource, 64),
            'utm_medium' => $this->trim($event->utmMedium, 64),
            'utm_campaign' => $this->trim($event->utmCampaign, 96),
            'session_id' => $this->trim($event->sessionId, 64),
            'value_minor' => $event->valueMinor,
            'metadata' => $event->metadata === [] ? null : json_encode($event->metadata),
            'occurred_at' => $occurredAt,
            'dedup_key' => $this->trim($event->dedupKey, 191),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** Client-supplied labels are bounded rather than trusted; an over-long value is truncated. */
    private function trim(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $length);
    }
}
