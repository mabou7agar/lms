<?php

use App\Domains\Crm\Events\LeadCreated;
use App\Domains\Crm\Models\Lead;
use App\Platform\Notifications\Enums\CampaignStatus;
use App\Platform\Notifications\Models\AutomationRule;
use App\Platform\Notifications\Models\AutomationRun;
use App\Platform\Notifications\Models\CampaignEnrollment;
use App\Platform\Notifications\Models\MarketingCampaign;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(TenantContext::class)->set(TenantId::from(1));
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

if (! function_exists('ruleFor')) {
    function ruleFor(string $triggerKey, array $conditions = []): AutomationRule
    {
        return AutomationRule::create([
            'name' => 'Rule',
            'trigger_type' => 'event',
            'trigger_key' => $triggerKey,
            'conditions' => $conditions,
            'is_active' => true,
        ]);
    }
}

it('fires exactly once on its trigger event and performs its action (tag the lead)', function (): void {
    $rule = ruleFor('crm.lead.created');
    $rule->actions()->create([
        'action_type' => 'tag_lead',
        'template_key' => 'n/a',
        'category' => 'marketing',
        'config' => ['tag' => 'inbound'],
    ]);

    $lead = Lead::factory()->create(['marketing_consent' => true]);

    LeadCreated::dispatch($lead);
    LeadCreated::dispatch($lead); // redispatch: must NOT fire the rule again

    expect(AutomationRun::count())->toBe(1)
        ->and($lead->fresh()->tags()->where('name', 'inbound')->count())->toBe(1);
});

it('enqueues the subject lead into a campaign and is idempotent under redispatch', function (): void {
    $campaign = MarketingCampaign::factory()->organization(1)->create(['status' => CampaignStatus::Active->value]);
    $campaign->steps()->create(['position' => 1, 'delay_minutes' => 0, 'template_key' => 'welcome_1', 'channel' => 'email']);

    $rule = ruleFor('crm.lead.created');
    $rule->actions()->create([
        'action_type' => 'enqueue_campaign',
        'template_key' => 'n/a',
        'category' => 'marketing',
        'config' => ['campaign' => $campaign->public_id],
    ]);

    $lead = Lead::factory()->create(['marketing_consent' => true]);

    LeadCreated::dispatch($lead);
    LeadCreated::dispatch($lead);

    expect(AutomationRun::count())->toBe(1)
        ->and(CampaignEnrollment::where('marketing_campaign_id', $campaign->id)->count())->toBe(1);

    $enrollment = CampaignEnrollment::where('marketing_campaign_id', $campaign->id)->firstOrFail();
    expect($enrollment->recipient_type)->toBe('lead')
        ->and($enrollment->recipient_id)->toBe($lead->id)
        ->and($enrollment->consent_snapshot)->toBeTrue();
});

it('does not fire when conditions are not met', function (): void {
    $rule = ruleFor('crm.lead.created', ['source' => 'web']);
    $rule->actions()->create([
        'action_type' => 'tag_lead',
        'template_key' => 'n/a',
        'category' => 'marketing',
        'config' => ['tag' => 'inbound'],
    ]);

    $lead = Lead::factory()->create(['source' => 'referral', 'marketing_consent' => true]);

    LeadCreated::dispatch($lead);

    expect(AutomationRun::count())->toBe(0)
        ->and($lead->fresh()->tags()->count())->toBe(0);
});

it('does not fire an inactive rule', function (): void {
    $rule = ruleFor('crm.lead.created');
    $rule->update(['is_active' => false]);
    $rule->actions()->create([
        'action_type' => 'tag_lead', 'template_key' => 'n/a', 'category' => 'marketing', 'config' => ['tag' => 'x'],
    ]);

    LeadCreated::dispatch(Lead::factory()->create());

    expect(AutomationRun::count())->toBe(0);
});
