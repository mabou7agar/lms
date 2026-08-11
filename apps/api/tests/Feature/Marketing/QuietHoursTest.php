<?php

use App\Domains\Crm\Models\Lead;
use App\Platform\Notifications\Enums\EnrollmentStatus;
use App\Platform\Notifications\Enums\MarketingSendStatus;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Models\CampaignSend;
use App\Platform\Notifications\Models\MarketingCampaign;
use App\Platform\Notifications\Models\NotificationTemplate;
use App\Platform\Notifications\Services\CampaignEnrollmentService;
use App\Platform\Notifications\Services\CampaignRunner;
use App\Platform\Notifications\Services\MarketingDispatcher;
use App\Platform\Notifications\Enums\Channel;
use App\Platform\Shared\Marketing\Data\MarketingRecipient;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(TenantContext::class)->set(TenantId::from(1));
    config(['notifications.marketing.quiet_hours' => ['enabled' => true, 'start' => '21:00', 'end' => '08:00']]);
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
    Carbon::setTestNow();
});

it('defers a marketing message inside quiet hours to the window end', function (): void {
    Carbon::setTestNow('2026-08-11 23:00:00'); // inside 21:00-08:00 (UTC recipient)

    $lead = Lead::factory()->create(['marketing_consent' => true]);
    $recipient = new MarketingRecipient('lead', $lead->id, (string) $lead->email, 'UTC', 'en', true);

    $result = app(MarketingDispatcher::class)->send(
        1, $recipient, NotificationCategory::Marketing, 'promo', Channel::Email
    );

    expect($result->wasDeferred())->toBeTrue()
        ->and($result->deferredUntil->format('Y-m-d H:i'))->toBe('2026-08-12 08:00');
});

it('sends a transactional message immediately even inside quiet hours (bypass)', function (): void {
    Carbon::setTestNow('2026-08-11 23:00:00');

    $lead = Lead::factory()->create(['marketing_consent' => false]); // consent irrelevant for transactional
    $recipient = new MarketingRecipient('lead', $lead->id, (string) $lead->email, 'UTC', 'en', false);

    $result = app(MarketingDispatcher::class)->send(
        1, $recipient, NotificationCategory::Account, 'password_reset', Channel::Email
    );

    expect($result->wasSent())->toBeTrue();
});

it('a drip step lands as deferred inside quiet hours, then sends after the window (resumable)', function (): void {
    Carbon::setTestNow('2026-08-11 23:00:00');

    NotificationTemplate::factory()->create(['key' => 'qh_1', 'channel' => 'email', 'locale' => 'en', 'subject' => 'S', 'body' => 'B']);
    $campaign = MarketingCampaign::factory()->organization(1)->create();
    $campaign->steps()->create(['position' => 1, 'delay_minutes' => 0, 'template_key' => 'qh_1', 'channel' => 'email']);

    $lead = Lead::factory()->create(['marketing_consent' => true]);
    app(CampaignEnrollmentService::class)->enroll($campaign, new MarketingRecipient('lead', $lead->id, (string) $lead->email, 'UTC', 'en', true));

    $runner = app(CampaignRunner::class);
    $runner->advanceDue();

    $enrollment = $campaign->enrollments()->firstOrFail();
    expect($enrollment->current_step)->toBe(0) // step NOT advanced — deferred, not dropped
        ->and($enrollment->status)->toBe(EnrollmentStatus::Active)
        ->and($enrollment->next_run_at->format('Y-m-d H:i'))->toBe('2026-08-12 08:00')
        ->and(CampaignSend::where('status', MarketingSendStatus::Deferred->value)->count())->toBe(1);

    // After the window ends, the same step goes out.
    Carbon::setTestNow('2026-08-12 08:00:00');
    $runner->advanceDue();

    $enrollment->refresh();
    expect($enrollment->current_step)->toBe(1)
        ->and(CampaignSend::where('status', MarketingSendStatus::Sent->value)->count())->toBe(1);
});
