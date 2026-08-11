<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Services;

use App\Platform\Shared\Marketing\Data\MarketingRecipient;

/**
 * The single source of truth for which domain events drive marketing automations, and how each maps
 * to a trigger key, subject, dedupe key, condition context and marketing recipient.
 *
 * DEPTRAC-CRUCIAL: every domain event is referenced ONLY by its fully-qualified class name as a
 * STRING LITERAL. There is NO `use` of any CRM/Learning/Commerce event or model here, so the
 * Notifications context gains no static edge to another context and the Shared + IdentityContracts
 * ruleset holds. Events are read as opaque objects via data_get() by dotted path (mirrors the
 * outbound webhook catalog pattern).
 */
final class AutomationEventCatalog
{
    private const LEAD_CREATED = 'App\\Domains\\Crm\\Events\\LeadCreated';

    private const LEAD_STAGE_MOVED = 'App\\Domains\\Crm\\Events\\LeadStageMoved';

    /** @var array<string, AutomationEventMapping>|null */
    private ?array $map = null;

    /** @return array<string, AutomationEventMapping> keyed by domain event class-string. */
    public function map(): array
    {
        return $this->map ??= [
            self::LEAD_CREATED => new AutomationEventMapping(
                triggerKey: 'crm.lead.created',
                subjectKey: fn (object $e): string => 'lead:'.$this->str($e, 'lead.id'),
                eventKey: fn (object $e): string => 'crm.lead.created:'.$this->str($e, 'lead.id'),
                context: fn (object $e): array => $this->leadContext($e),
                recipient: fn (object $e): ?MarketingRecipient => $this->leadRecipient($e),
            ),

            self::LEAD_STAGE_MOVED => new AutomationEventMapping(
                triggerKey: 'crm.lead.stage_moved',
                subjectKey: fn (object $e): string => 'lead:'.$this->str($e, 'lead.id'),
                eventKey: fn (object $e): string => 'crm.lead.stage_moved:'.$this->str($e, 'lead.id').':'.$this->str($e, 'lead.stage_id'),
                context: fn (object $e): array => $this->leadContext($e),
                recipient: fn (object $e): ?MarketingRecipient => $this->leadRecipient($e),
            ),
        ];
    }

    /** The domain event class-strings to subscribe to (Event::listen by string name). */
    /** @return list<string> */
    public function eventClasses(): array
    {
        return array_keys($this->map());
    }

    public function for(string $eventClass): ?AutomationEventMapping
    {
        return $this->map()[$eventClass] ?? null;
    }

    /** @return array<string, mixed> */
    private function leadContext(object $event): array
    {
        return [
            'lead_id' => $this->int($event, 'lead.id'),
            'email' => $this->str($event, 'lead.email'),
            'name' => $this->str($event, 'lead.name'),
            'status' => $this->str($event, 'lead.status.value') ?? $this->str($event, 'lead.status'),
            'source' => $this->str($event, 'lead.source'),
            'utm_source' => $this->str($event, 'lead.utm_source'),
            'utm_campaign' => $this->str($event, 'lead.utm_campaign'),
            'country' => $this->str($event, 'lead.country'),
            'owner_id' => $this->int($event, 'lead.owner_id'),
            'marketing_consent' => (bool) data_get($event, 'lead.marketing_consent'),
        ];
    }

    private function leadRecipient(object $event): ?MarketingRecipient
    {
        $id = $this->int($event, 'lead.id');
        $email = $this->str($event, 'lead.email');

        if ($id === null || $email === null || $email === '') {
            return null;
        }

        return new MarketingRecipient(
            recipientType: 'lead',
            recipientId: $id,
            email: $email,
            timezone: $this->str($event, 'lead.timezone'),
            locale: null,
            hasConsent: (bool) data_get($event, 'lead.marketing_consent'),
        );
    }

    private function str(object $event, string $path): ?string
    {
        $value = data_get($event, $path);

        return is_scalar($value) ? (string) $value : null;
    }

    private function int(object $event, string $path): ?int
    {
        $value = data_get($event, $path);

        return is_numeric($value) ? (int) $value : null;
    }
}
