<?php

namespace App\Platform\Shared\Analytics\Data;

/**
 * One analytics event as a producing context describes it.
 *
 * Every field is optional except the name, because every producer knows a different subset: a refund
 * knows an order and no course, a lesson completion knows a course and no order. The dimensions are
 * denormalised deliberately — a funnel query groups by them across millions of rows, and resolving
 * them later through tables that may since have been edited would be both slower and wrong.
 *
 * `dedupKey` is what makes recording safe from a retried webhook or a double-submitted form. Give
 * it a value derived from the thing that happened (an order id, a question id) — never from the
 * clock, or every retry becomes a new "fact".
 *
 * `metadata` is for small non-identifying extras. Nothing here may carry PII: the actor is a user
 * id, and everything else is an id, a count or a label.
 */
final readonly class AnalyticsEventInput
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $name,
        public ?int $userId = null,
        public ?int $organizationId = null,
        public ?int $courseId = null,
        public ?int $productId = null,
        public ?int $orderId = null,
        public ?int $instructorId = null,
        public ?string $productType = null,
        public ?string $buyerType = null,
        public ?int $valueMinor = null,
        public ?string $sessionId = null,
        public ?string $utmSource = null,
        public ?string $utmMedium = null,
        public ?string $utmCampaign = null,
        public array $metadata = [],
        public ?string $dedupKey = null,
        public ?string $occurredAt = null,
    ) {}
}
