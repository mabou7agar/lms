<?php

declare(strict_types=1);

namespace App\Platform\Integration\Emission;

/**
 * The single source of truth for OUTBOUND event emission: which domain events are published as
 * customer webhooks, under what public name, and how each event's whitelisted payload + idempotency
 * key are derived.
 *
 * DEPTRAC-CRUCIAL: every domain event is referenced ONLY by its fully-qualified class name as a
 * STRING LITERAL. There is NO `use` of any domain event/model class here (or anywhere in the
 * Integration layer), so no static edge is created and the Shared + IdentityContracts ruleset holds.
 * Events are read as opaque `object`s through EventValue (data_get by dotted path).
 */
final class WebhookEventCatalog
{
    // Domain event class strings (NOT imports). If a domain renames an event, only this map changes.
    private const ENROLLED = 'App\\Contexts\\Learning\\Events\\UserEnrolled';

    private const COURSE_COMPLETED = 'App\\Contexts\\Learning\\Events\\CourseCompleted';

    private const CERTIFICATE_ISSUED = 'App\\Domains\\Certification\\Events\\CertificateIssued';

    private const ORDER_PAID = 'App\\Contexts\\Commerce\\Events\\OrderPaid';

    private const ORDER_REFUNDED = 'App\\Contexts\\Commerce\\Events\\OrderRefunded';

    private const SUBSCRIPTION_CREATED = 'App\\Contexts\\Commerce\\Events\\SubscriptionCreated';

    private const SUBSCRIPTION_RENEWED = 'App\\Contexts\\Commerce\\Events\\SubscriptionRenewed';

    private const SUBSCRIPTION_CANCELED = 'App\\Contexts\\Commerce\\Events\\SubscriptionCanceled';

    /** @var array<string, WebhookEventMapping>|null */
    private ?array $map = null;

    /** @return array<string, WebhookEventMapping> keyed by domain event class-string. */
    public function map(): array
    {
        return $this->map ??= [
            self::ENROLLED => new WebhookEventMapping(
                'enrollment.created',
                fn (object $e): array => [
                    'enrollment_id' => EventValue::string($e, 'enrollment.public_id'),
                    'user_id' => EventValue::int($e, 'enrollment.user_id'),
                    'course_id' => EventValue::int($e, 'enrollment.course_id'),
                    'status' => EventValue::string($e, 'enrollment.status.value'),
                    'enrolled_at' => EventValue::iso($e, 'enrollment.enrolled_at'),
                ],
                fn (object $e): string => 'enrollment.created:'.EventValue::string($e, 'enrollment.public_id'),
            ),

            self::COURSE_COMPLETED => new WebhookEventMapping(
                'course.completed',
                fn (object $e): array => [
                    'enrollment_id' => EventValue::string($e, 'enrollment.public_id'),
                    'user_id' => EventValue::int($e, 'enrollment.user_id'),
                    'course_id' => EventValue::int($e, 'enrollment.course_id'),
                    'progress_percentage' => EventValue::int($e, 'enrollment.progress_percentage'),
                    'completed_at' => EventValue::iso($e, 'enrollment.completed_at'),
                ],
                fn (object $e): string => 'course.completed:'.EventValue::string($e, 'enrollment.public_id'),
            ),

            self::CERTIFICATE_ISSUED => new WebhookEventMapping(
                'certificate.issued',
                fn (object $e): array => [
                    'certificate_id' => EventValue::string($e, 'certificate.public_id'),
                    'number' => EventValue::string($e, 'certificate.number'),
                    'user_id' => EventValue::int($e, 'certificate.user_id'),
                    'course_id' => EventValue::int($e, 'certificate.course_id'),
                    'verification_code' => EventValue::string($e, 'certificate.verification_code'),
                    'issued_at' => EventValue::iso($e, 'certificate.issued_at'),
                ],
                fn (object $e): string => 'certificate.issued:'.EventValue::string($e, 'certificate.public_id'),
            ),

            self::ORDER_PAID => new WebhookEventMapping(
                'payment.succeeded',
                fn (object $e): array => [
                    'order_id' => EventValue::string($e, 'order.public_id'),
                    'user_id' => EventValue::int($e, 'order.user_id'),
                    'total_minor' => EventValue::int($e, 'order.total_minor'),
                    'currency' => EventValue::string($e, 'order.currency'),
                    'status' => EventValue::string($e, 'order.status.value'),
                    'paid_at' => EventValue::iso($e, 'order.paid_at'),
                ],
                fn (object $e): string => 'payment.succeeded:'.EventValue::string($e, 'order.public_id'),
            ),

            self::ORDER_REFUNDED => new WebhookEventMapping(
                'refund.completed',
                fn (object $e): array => [
                    'order_id' => EventValue::string($e, 'order.public_id'),
                    'user_id' => EventValue::int($e, 'order.user_id'),
                    'total_minor' => EventValue::int($e, 'order.total_minor'),
                    'currency' => EventValue::string($e, 'order.currency'),
                    'refunded_at' => EventValue::iso($e, 'order.refunded_at'),
                ],
                fn (object $e): string => 'refund.completed:'.EventValue::string($e, 'order.public_id'),
            ),

            self::SUBSCRIPTION_CREATED => new WebhookEventMapping(
                'subscription.created',
                fn (object $e): array => [
                    'subscription_id' => EventValue::int($e, 'subscriptionId'),
                    'user_id' => EventValue::int($e, 'userId'),
                    'plan_id' => EventValue::int($e, 'planId'),
                ],
                fn (object $e): string => 'subscription.created:'.EventValue::string($e, 'subscriptionId'),
            ),

            self::SUBSCRIPTION_RENEWED => new WebhookEventMapping(
                'subscription.renewed',
                fn (object $e): array => [
                    'subscription_id' => EventValue::int($e, 'subscriptionId'),
                    'user_id' => EventValue::int($e, 'userId'),
                    'plan_id' => EventValue::int($e, 'planId'),
                    'amount_minor' => EventValue::int($e, 'amountMinor'),
                    'currency' => EventValue::string($e, 'currency'),
                ],
                // A renewal repeats per billing period; key on subscription + amount + currency so distinct
                // renewals deliver while a redispatched identical renewal is de-duplicated.
                fn (object $e): string => 'subscription.renewed:'.EventValue::string($e, 'subscriptionId')
                    .':'.EventValue::string($e, 'amountMinor').':'.EventValue::string($e, 'currency'),
            ),

            self::SUBSCRIPTION_CANCELED => new WebhookEventMapping(
                'subscription.cancelled',
                fn (object $e): array => [
                    'subscription_id' => EventValue::int($e, 'subscriptionId'),
                    'user_id' => EventValue::int($e, 'userId'),
                    'immediate' => EventValue::bool($e, 'immediate'),
                ],
                fn (object $e): string => 'subscription.cancelled:'.EventValue::string($e, 'subscriptionId'),
            ),
        ];
    }

    /** The domain event class-strings to subscribe to (used for Event::listen by string name). */
    /** @return list<string> */
    public function eventClasses(): array
    {
        return array_keys($this->map());
    }

    /** All distinct public webhook event names (for API validation + admin display). */
    /** @return list<string> */
    public function eventNames(): array
    {
        return array_values(array_unique(array_map(
            static fn (WebhookEventMapping $m): string => $m->name,
            $this->map(),
        )));
    }

    public function for(string $eventClass): ?WebhookEventMapping
    {
        return $this->map()[$eventClass] ?? null;
    }
}
