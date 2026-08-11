<?php

use App\Domains\Crm\Models\Lead;
use App\Platform\Identity\Models\User;
use App\Platform\Notifications\Enums\Channel;
use App\Platform\Notifications\Enums\DeliveryStatus;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Enums\SuppressionSource;
use App\Platform\Notifications\Models\MarketingSuppression;
use App\Platform\Notifications\Models\Notification;
use App\Platform\Notifications\Services\MarketingDispatcher;
use App\Platform\Notifications\Services\NotificationDispatcher;
use App\Platform\Shared\Marketing\Data\MarketingRecipient;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(TenantContext::class)->set(TenantId::from(1));
    config(['notifications.marketing.quiet_hours.enabled' => false]);
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

it('skips a marketing send when the recipient has not consented', function (): void {
    $lead = Lead::factory()->create(['marketing_consent' => false]);
    $recipient = new MarketingRecipient('lead', $lead->id, (string) $lead->email, 'UTC', 'en', false);

    $result = app(MarketingDispatcher::class)->send(1, $recipient, NotificationCategory::Marketing, 'promo', Channel::Email);

    expect($result->status->value)->toBe('skipped_no_consent');
});

it('skips a marketing send to a suppressed (unsubscribed) recipient', function (): void {
    $lead = Lead::factory()->create(['marketing_consent' => true]);
    MarketingSuppression::create([
        'organization_id' => 1,
        'email' => $lead->email,
        'category' => 'marketing',
        'source' => SuppressionSource::UnsubscribeLink->value,
        'suppressed_at' => now(),
    ]);

    $recipient = new MarketingRecipient('lead', $lead->id, (string) $lead->email, 'UTC', 'en', true);
    $result = app(MarketingDispatcher::class)->send(1, $recipient, NotificationCategory::Marketing, 'promo', Channel::Email);

    expect($result->status->value)->toBe('skipped_suppressed');
});

it('still delivers a TRANSACTIONAL message to a suppressed / non-consented recipient', function (): void {
    $email = 'person@example.test';

    // The same person is both marketing-suppressed and marketing-non-consented...
    $lead = Lead::factory()->create(['email' => $email, 'marketing_consent' => false]);
    MarketingSuppression::create([
        'organization_id' => 1, 'email' => $email, 'category' => 'marketing',
        'source' => SuppressionSource::UnsubscribeLink->value, 'suppressed_at' => now(),
    ]);

    $marketing = app(MarketingDispatcher::class)->send(
        1, new MarketingRecipient('lead', $lead->id, $email, 'UTC', 'en', false),
        NotificationCategory::Marketing, 'promo', Channel::Email
    );
    expect($marketing->status->value)->toBe('skipped_no_consent');

    // ...yet a transactional notification to their user account is delivered normally.
    $user = User::factory()->create(['email' => $email]);
    app(NotificationDispatcher::class)->dispatchToUserId($user->id, NotificationCategory::Account, 'security_alert', []);

    $notification = Notification::where('user_id', $user->id)->firstOrFail();
    $delivery = $notification->deliveries()->where('channel', 'in_app')->firstOrFail();

    expect($notification->category)->toBe(NotificationCategory::Account)
        ->and($delivery->status)->toBe(DeliveryStatus::Sent);
});
