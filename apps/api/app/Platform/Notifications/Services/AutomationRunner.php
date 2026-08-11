<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Notifications\Enums\AutomationActionType;
use App\Platform\Notifications\Enums\Channel;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Models\AutomationAction;
use App\Platform\Notifications\Models\AutomationRule;
use App\Platform\Notifications\Models\AutomationRun;
use App\Platform\Notifications\Models\MarketingCampaign;
use App\Platform\Shared\Marketing\Contracts\MarketingAudiencePort;
use App\Platform\Shared\Marketing\Data\MarketingRecipient;
use App\Platform\Shared\Services\BaseService;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantScope;

/**
 * The event-driven workflow engine. Mirrors the outbound-webhook subscriber: it receives a domain
 * event as an OPAQUE object, maps it via the string-keyed catalog (no cross-context imports), and
 * evaluates the ACTIVE automation rules of the event's tenant.
 *
 * For each matching rule it performs the rule's allow-listed actions EXACTLY ONCE per
 * (rule, subject, event) — the automation_runs unique index makes a redispatched/replayed event a
 * no-op. Actions are a small, safe set: enqueue a (transactional) notification, enrol the subject
 * into a marketing campaign, or tag the lead. An unknown action_type is ignored, never executed.
 */
class AutomationRunner extends BaseService
{
    public function __construct(
        private readonly AutomationEventCatalog $catalog,
        private readonly TenantContext $tenant,
        private readonly NotificationDispatcher $dispatcher,
        private readonly CampaignEnrollmentService $enrollments,
        private readonly MarketingAudiencePort $audience,
    ) {}

    public function handle(object $event): void
    {
        $mapping = $this->catalog->for($event::class);

        if ($mapping === null) {
            return;
        }

        $tenantId = $this->tenant->id()?->value;

        // Explicit tenant fence (mirrors the webhook emitter): a resolved tenant matches ONLY its own
        // rules; with no tenant, only platform-level (NULL-org) rules. Org A's rules never fire on B.
        $rulesQuery = AutomationRule::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('is_active', true)
            ->where('trigger_type', 'event')
            ->where('trigger_key', $mapping->triggerKey)
            ->with('actions');

        $rulesQuery = $tenantId === null
            ? $rulesQuery->whereNull('organization_id')
            : $rulesQuery->where('organization_id', $tenantId);

        $rules = $rulesQuery->get();

        if ($rules->isEmpty()) {
            return;
        }

        $context = $mapping->contextFor($event);
        $subjectKey = $mapping->subjectKeyFor($event);
        $eventKey = $mapping->eventKeyFor($event);
        $recipient = $mapping->recipientFor($event);

        foreach ($rules as $rule) {
            if (! $this->conditionsMet($rule, $context)) {
                continue;
            }

            // Fires-once ledger: the losing insert on a concurrent/repeated event hits the unique
            // index, so wasRecentlyCreated is false and the actions are not re-run.
            $run = AutomationRun::query()->firstOrCreate(
                [
                    'automation_rule_id' => $rule->id,
                    'subject_key' => $subjectKey,
                    'event_key' => $eventKey,
                ],
                [
                    'organization_id' => $tenantId,
                    'fired_at' => now(),
                ],
            );

            if (! $run->wasRecentlyCreated) {
                continue;
            }

            foreach ($rule->actions as $action) {
                $this->performAction($action, $context, $recipient, $tenantId);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function performAction(AutomationAction $action, array $context, ?MarketingRecipient $recipient, int|string|null $tenantId): void
    {
        if ($action->action_type === AutomationActionType::SendNotification) {
            $this->sendNotification($action, $context);

            return;
        }

        if ($action->action_type === AutomationActionType::EnqueueCampaign) {
            $this->enqueueCampaign($action, $recipient, $tenantId);

            return;
        }

        if ($action->action_type === AutomationActionType::TagLead) {
            $this->tagLead($action, $recipient);
        }
    }

    /**
     * A transactional notification to the subject's owning user. Routed through the standard
     * dispatcher, so it bypasses quiet hours/consent/suppression by design (not a marketing send).
     *
     * @param  array<string, mixed>  $context
     */
    private function sendNotification(AutomationAction $action, array $context): void
    {
        $userId = $context['owner_id'] ?? null;

        if (! is_int($userId)) {
            return; // no user to notify (e.g. a guest lead with no owner)
        }

        $channels = array_map(
            static fn (string $c): Channel => Channel::from($c),
            (array) ($action->channels ?? ['in_app']),
        );

        $this->dispatcher->dispatchToUserId(
            $userId,
            NotificationCategory::from($action->category),
            $action->template_key,
            $context,
            $channels,
        );
    }

    private function enqueueCampaign(AutomationAction $action, ?MarketingRecipient $recipient, int|string|null $tenantId): void
    {
        $campaignRef = (string) (data_get($action->config, 'campaign', ''));

        if ($recipient === null || $campaignRef === '') {
            return;
        }

        $query = MarketingCampaign::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('public_id', $campaignRef);

        $query = $tenantId === null
            ? $query->whereNull('organization_id')
            : $query->where('organization_id', $tenantId);

        $campaign = $query->first();

        if ($campaign === null) {
            return;
        }

        $this->enrollments->enroll($campaign, $recipient);
    }

    private function tagLead(AutomationAction $action, ?MarketingRecipient $recipient): void
    {
        $tag = (string) (data_get($action->config, 'tag', ''));

        if ($recipient === null || $tag === '') {
            return;
        }

        $this->audience->tagRecipient($recipient->recipientType, $recipient->recipientId, $tag);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function conditionsMet(AutomationRule $rule, array $context): bool
    {
        foreach ((array) ($rule->conditions ?? []) as $key => $expected) {
            if (($context[$key] ?? null) != $expected) {
                return false;
            }
        }

        return true;
    }
}
