<?php

namespace App\Domains\Crm\Actions\Lead;

use App\Domains\Crm\Enums\ActivityType;
use App\Domains\Crm\Enums\LeadStatus;
use App\Domains\Crm\Events\LeadCreated;
use App\Domains\Crm\Models\Lead;
use App\Domains\Crm\Models\Pipeline;
use App\Domains\Crm\Services\ActivityLogger;
use App\Domains\Crm\Services\LeadScoringService;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Html\HtmlSanitizer;
use Illuminate\Support\Carbon;

/**
 * Intake for the PUBLIC (guest) enterprise-lead funnel.
 *
 * Trust boundary: this runs unauthenticated. It NEVER trusts a client-supplied tenant/owner — a
 * public lead is global (no organization_id column on crm_leads) and the owner is assigned from
 * server config only. Free text is sanitised, consent is recorded on the lead itself (guests have
 * no user account, so Identity's user_consents is neither reachable nor applicable), and a repeat
 * submission inside a short window updates the existing lead instead of creating a duplicate.
 */
class SubmitPublicLeadAction extends BaseAction
{
    public function __construct(
        private readonly ActivityLogger $log,
        private readonly LeadScoringService $scoring,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated public-lead payload
     * @param  string|null  $ip  request IP (consent audit only — never logged as PII)
     */
    public function execute(array $data, ?string $ip = null): Lead
    {
        $email = mb_strtolower(trim((string) ($data['work_email'] ?? '')));
        $companyName = trim((string) ($data['company'] ?? ''));

        /** @var array<string, mixed> $utm */
        $utm = is_array($data['utm'] ?? null) ? $data['utm'] : [];

        // Sanitise the only free-text field; strip all HTML so nothing is ever stored or rendered raw.
        $message = isset($data['message']) && is_string($data['message'])
            ? trim(strip_tags($this->sanitizer->sanitize($data['message'])))
            : null;

        $score = $this->scoring->score([
            'request_type' => $data['request_type'] ?? null,
            'company_size' => $data['company_size'] ?? null,
            'utm_medium' => $utm['medium'] ?? null,
            'gclid' => $data['gclid'] ?? null,
            'email' => $email,
        ]);

        $consent = (bool) ($data['marketing_consent'] ?? false);

        $lead = $this->transaction(function () use ($data, $utm, $email, $companyName, $message, $score, $consent, $ip): Lead {
            $existing = $this->findRecentDuplicate($email, $companyName);

            $attributes = [
                'name' => trim((string) $data['name']),
                'email' => $email,
                'company_name' => $companyName !== '' ? $companyName : null,
                'phone' => $this->nullable($data['phone'] ?? null),
                'request_type' => $this->nullable($data['request_type'] ?? null),
                'company_size' => $this->nullable($data['company_size'] ?? null),
                'country' => $this->nullable($data['country'] ?? null),
                'source' => (string) config('crm.public_lead.source', 'enterprise_funnel'),
                'utm_source' => $this->nullable($utm['source'] ?? null),
                'utm_medium' => $this->nullable($utm['medium'] ?? null),
                'utm_campaign' => $this->nullable($utm['campaign'] ?? null),
                'utm_term' => $this->nullable($utm['term'] ?? null),
                'utm_content' => $this->nullable($utm['content'] ?? null),
                'gclid' => $this->nullable($data['gclid'] ?? null),
                'referrer' => $this->nullable($data['referrer'] ?? null),
                'landing_path' => $this->nullable($data['source_page'] ?? null),
                'lead_score' => $score,
                'last_contacted_at' => now(),
            ];

            // Consent is only ever upgraded (grant), never silently revoked on a re-submit.
            if ($consent) {
                $attributes['marketing_consent'] = true;
                $attributes['consent_version'] = (string) config('crm.public_lead.consent_version');
                $attributes['consented_at'] = now();
                $attributes['consent_ip'] = $ip;
            }

            if ($existing !== null) {
                $existing->fill(array_filter(
                    $attributes,
                    static fn ($v) => $v !== null,
                ))->save();

                $this->log->log($existing, ActivityType::System, 'Enterprise lead re-submitted via funnel', $existing->owner_id);
                if ($message !== null && $message !== '') {
                    $this->log->log($existing, ActivityType::Note, $message, $existing->owner_id);
                }

                return $existing;
            }

            $pipeline = Pipeline::where('is_default', true)->first();
            $stage = $pipeline?->stages()->orderBy('position')->first();
            $ownerId = $this->defaultOwnerId();

            $lead = Lead::create(array_merge($attributes, [
                'pipeline_id' => $pipeline?->id,
                'stage_id' => $stage?->id,
                'owner_id' => $ownerId,
                'status' => LeadStatus::New->value,
                'next_follow_up_at' => now()->addDay(),
            ]));

            // Sales inbox signal (ConsultingRequestCreated-style: the timeline IS the notification).
            $this->log->log($lead, ActivityType::System, 'New enterprise lead received — assign & follow up', $ownerId);
            if ($message !== null && $message !== '') {
                $this->log->log($lead, ActivityType::Note, $message, $ownerId);
            }

            return $lead;
        });

        // Only a brand-new lead fires LeadCreated (reused domain event → LogLeadCreatedActivity +
        // any future sales notifier). Dedup updates do not re-fire it.
        if ($lead->wasRecentlyCreated) {
            LeadCreated::dispatch($lead);
        }

        return $lead;
    }

    private function findRecentDuplicate(string $email, string $companyName): ?Lead
    {
        if ($email === '') {
            return null;
        }

        $window = (int) config('crm.public_lead.dedup_window_minutes', 60);
        $since = Carbon::now()->subMinutes($window);

        return Lead::query()
            ->where('email', $email)
            ->where('company_name', $companyName !== '' ? $companyName : null)
            ->where('source', (string) config('crm.public_lead.source', 'enterprise_funnel'))
            ->where('created_at', '>=', $since)
            ->latest('id')
            ->first();
    }

    private function defaultOwnerId(): ?int
    {
        $configured = config('crm.public_lead.default_owner_id');

        return $configured !== null && $configured !== '' ? (int) $configured : null;
    }

    private function nullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
