<?php

declare(strict_types=1);

namespace App\Platform\Integration\Emission;

use Closure;

/**
 * One entry in the outbound event catalog: how a single domain event class (referenced ONLY by its
 * string FQCN in the catalog) maps to a customer-facing webhook.
 *
 * - $name    the public webhook event name (e.g. "course.completed").
 * - $payload extracts the whitelisted, boundary-safe payload from the event object.
 * - $dedupe  derives a deterministic idempotency key from the event so re-dispatching the same
 *            real-world occurrence never double-delivers to an endpoint.
 */
final class WebhookEventMapping
{
    /**
     * @param  Closure(object): array<string, mixed>  $payload
     * @param  Closure(object): string  $dedupe
     */
    public function __construct(
        public readonly string $name,
        public readonly Closure $payload,
        public readonly Closure $dedupe,
    ) {}

    /** @return array<string, mixed> */
    public function payloadFor(object $event): array
    {
        return ($this->payload)($event);
    }

    public function dedupeFor(object $event): string
    {
        return ($this->dedupe)($event);
    }
}
