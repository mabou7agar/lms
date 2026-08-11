<?php

use App\Domains\Crm\Models\Lead;
use App\Platform\Notifications\Enums\EnrollmentStatus;
use App\Platform\Notifications\Enums\MarketingSendStatus;
use App\Platform\Notifications\Models\CampaignSend;
use App\Platform\Notifications\Models\MarketingCampaign;
use App\Platform\Notifications\Models\NotificationTemplate;
use App\Platform\Notifications\Services\CampaignEnrollmentService;
use App\Platform\Notifications\Services\CampaignRunner;
use App\Platform\Shared\Marketing\Data\MarketingRecipient;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(TenantContext::class)->set(TenantId::from(1));
    // Isolate drip advancement from quiet-hours deferral (covered by its own test).
    config(['notifications.marketing.quiet_hours.enabled' => false]);
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
    Carbon::setTestNow();
});

function twoStepCampaign(int $secondDelayMinutes = 60): MarketingCampaign
{
    $campaign = MarketingCampaign::factory()->organization(1)->create();
    $campaign->steps()->create(['position' => 1, 'delay_minutes' => 0, 'template_key' => 'drip_1', 'channel' => 'email']);
    $campaign->steps()->create(['position' => 2, 'delay_minutes' => $secondDelayMinutes, 'template_key' => 'drip_2', 'channel' => 'email']);

    foreach (['drip_1', 'drip_2'] as $key) {
        NotificationTemplate::factory()->create([
            'key' => $key, 'channel' => 'email', 'locale' => 'en',
            'subject' => 'Hello', 'body' => 'Body {{ unsubscribe_url }}',
        ]);
    }

    return $campaign;
}

function enrollLead(MarketingCampaign $campaign, bool $consent = true): void
{
    $lead = Lead::factory()->create(['marketing_consent' => $consent]);
    app(CampaignEnrollmentService::class)->enroll($campaign, new MarketingRecipient(
        recipientType: 'lead',
        recipientId: $lead->id,
        email: (string) $lead->email,
        timezone: 'UTC',
        locale: 'en',
        hasConsent: $consent,
    ));
}

it('advances a drip one step at a time on schedule and completes', function (): void {
    Carbon::setTestNow('2026-08-11 12:00:00');

    $campaign = twoStepCampaign(60);
    enrollLead($campaign);

    $runner = app(CampaignRunner::class);

    // First tick: step 1 is due immediately (delay 0).
    expect($runner->advanceDue())->toBe(1);
    $enrollment = $campaign->enrollments()->firstOrFail();
    expect($enrollment->current_step)->toBe(1)
        ->and(CampaignSend::where('position', 1)->where('status', MarketingSendStatus::Sent->value)->count())->toBe(1)
        ->and($enrollment->fresh()->next_run_at->format('H:i'))->toBe('13:00');

    // Not yet due: a tick before step 2's time is a no-op (idempotent).
    Carbon::setTestNow('2026-08-11 12:30:00');
    expect($runner->advanceDue())->toBe(0)
        ->and(CampaignSend::count())->toBe(1);

    // Step 2 becomes due at 13:00.
    Carbon::setTestNow('2026-08-11 13:00:00');
    expect($runner->advanceDue())->toBe(1);
    $enrollment->refresh();
    expect($enrollment->current_step)->toBe(2)
        ->and($enrollment->status)->toBe(EnrollmentStatus::Completed)
        ->and(CampaignSend::count())->toBe(2);

    // Resumable: a completed drip does nothing further.
    Carbon::setTestNow('2026-08-11 14:00:00');
    expect($runner->advanceDue())->toBe(0)
        ->and(CampaignSend::count())->toBe(2);
});

it('is idempotent across repeated ticks within the same due window (never double-sends a step)', function (): void {
    Carbon::setTestNow('2026-08-11 12:00:00');
    $campaign = twoStepCampaign(60);
    enrollLead($campaign);

    $runner = app(CampaignRunner::class);
    $runner->advanceDue();
    // A second immediate scan: step 2 is not due yet, step 1 already sent.
    $runner->advanceDue();
    $runner->advanceDue();

    expect(CampaignSend::where('position', 1)->count())->toBe(1);
});

it('does not reload steps per recipient — bounded queries on the advance (no N+1)', function (): void {
    Carbon::setTestNow('2026-08-11 12:00:00');

    // A campaign with MANY steps: an N+1 over steps would explode query count.
    $campaign = MarketingCampaign::factory()->organization(1)->create();
    for ($i = 1; $i <= 8; $i++) {
        $campaign->steps()->create(['position' => $i, 'delay_minutes' => $i === 1 ? 0 : 60, 'template_key' => 'drip_x', 'channel' => 'email']);
    }
    NotificationTemplate::factory()->create([
        'key' => 'drip_x', 'channel' => 'email', 'locale' => 'en', 'subject' => 'S', 'body' => 'B',
    ]);

    foreach (range(1, 3) as $n) {
        enrollLead($campaign);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    app(CampaignRunner::class)->advanceDue();

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $stepQueries = collect($log)->filter(fn ($q) => str_contains((string) $q['query'], 'campaign_steps'))->count();
    $campaignQueries = collect($log)->filter(fn ($q) => str_contains((string) $q['query'], 'from "marketing_campaigns"'))->count();

    // Steps and campaigns are eager-loaded ONCE regardless of step count or recipient count.
    expect($stepQueries)->toBe(1)
        ->and($campaignQueries)->toBe(1)
        // Total stays bounded (well below the 3*8 an N+1 over steps would add). Includes the +1
        // in-flight "Sending" claim written per advanced step (the double-send crash guard).
        ->and(count($log))->toBeLessThanOrEqual(48);
});
