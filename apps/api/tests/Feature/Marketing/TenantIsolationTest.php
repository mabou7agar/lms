<?php

use App\Domains\Crm\Events\LeadCreated;
use App\Domains\Crm\Models\Lead;
use App\Platform\Notifications\Models\AutomationRule;
use App\Platform\Notifications\Models\AutomationRun;
use App\Platform\Notifications\Models\MarketingCampaign;
use App\Platform\Notifications\Models\MarketingSuppression;
use App\Platform\Notifications\Services\MarketingDispatcher;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

function actAsOrg(int $org): void
{
    app(TenantContext::class)->forget();
    app(TenantContext::class)->set(TenantId::from($org));
}

it("never fires org A's automation rule on org B's event", function (): void {
    actAsOrg(1);
    $rule = AutomationRule::create([
        'name' => 'Org1 rule', 'trigger_type' => 'event', 'trigger_key' => 'crm.lead.created',
        'conditions' => [], 'is_active' => true,
    ]);
    $rule->actions()->create(['action_type' => 'tag_lead', 'template_key' => 'n/a', 'category' => 'marketing', 'config' => ['tag' => 'x']]);
    expect($rule->organization_id)->toBe(1);

    // Fire the event while acting as org 2 — org 1's rule must not match.
    actAsOrg(2);
    LeadCreated::dispatch(Lead::factory()->create(['marketing_consent' => true]));
    expect(AutomationRun::count())->toBe(0);

    // Same event under org 1 fires the rule.
    actAsOrg(1);
    LeadCreated::dispatch(Lead::factory()->create(['marketing_consent' => true]));
    expect(AutomationRun::where('automation_rule_id', $rule->id)->count())->toBe(1);
});

it('scopes campaigns to their tenant', function (): void {
    actAsOrg(1);
    MarketingCampaign::factory()->organization(1)->create();

    actAsOrg(2);
    expect(MarketingCampaign::count())->toBe(0);

    actAsOrg(1);
    expect(MarketingCampaign::count())->toBe(1);
});

it("does not treat org A's suppression as suppressing org B", function (): void {
    actAsOrg(1);
    MarketingSuppression::create([
        'organization_id' => 1, 'email' => 'shared@example.test', 'category' => 'marketing',
        'source' => 'unsubscribe_link', 'suppressed_at' => now(),
    ]);

    $dispatcher = app(MarketingDispatcher::class);

    expect($dispatcher->isSuppressed(1, 'shared@example.test', 'marketing'))->toBeTrue()
        ->and($dispatcher->isSuppressed(2, 'shared@example.test', 'marketing'))->toBeFalse();
});
