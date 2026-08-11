<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Services;

use App\Platform\Shared\Marketing\Data\MarketingRecipient;
use Closure;

/**
 * One entry in the automation event catalog: how a single domain event class (referenced ONLY by its
 * string FQCN in the catalog) is turned into an automation trigger.
 *
 *  - $triggerKey  the string an automation_rules.trigger_key must equal to match this event.
 *  - $subjectKey  stable identity of the acted-on aggregate (e.g. "lead:42") for the fires-once ledger.
 *  - $eventKey    deterministic dedupe key for the occurrence.
 *  - $context     whitelisted scalar payload for condition matching + template data.
 *  - $recipient   the marketing recipient the event concerns (or null when the event has none).
 */
final class AutomationEventMapping
{
    /**
     * @param  Closure(object): string  $subjectKey
     * @param  Closure(object): string  $eventKey
     * @param  Closure(object): array<string, mixed>  $context
     * @param  Closure(object): ?MarketingRecipient  $recipient
     */
    public function __construct(
        public readonly string $triggerKey,
        public readonly Closure $subjectKey,
        public readonly Closure $eventKey,
        public readonly Closure $context,
        public readonly Closure $recipient,
    ) {}

    public function subjectKeyFor(object $event): string
    {
        return ($this->subjectKey)($event);
    }

    public function eventKeyFor(object $event): string
    {
        return ($this->eventKey)($event);
    }

    /** @return array<string, mixed> */
    public function contextFor(object $event): array
    {
        return ($this->context)($event);
    }

    public function recipientFor(object $event): ?MarketingRecipient
    {
        return ($this->recipient)($event);
    }
}
