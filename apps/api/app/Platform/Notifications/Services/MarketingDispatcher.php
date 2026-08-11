<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Notifications\Contracts\Providers\MailProvider;
use App\Platform\Notifications\Data\MarketingSendResult;
use App\Platform\Notifications\Enums\Channel;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Models\MarketingSuppression;
use App\Platform\Notifications\Models\UserNotificationSetting;
use App\Platform\Notifications\Support\UnsubscribeLinkGenerator;
use App\Platform\Shared\Marketing\Contracts\MarketingAudiencePort;
use App\Platform\Shared\Marketing\Data\MarketingRecipient;
use App\Platform\Shared\Services\BaseService;
use App\Platform\Shared\Tenancy\TenantScope;

/**
 * The MARKETING delivery path — the single place the three marketing-only guardrails are enforced,
 * in order, before anything is sent:
 *
 *   1. CONSENT       live re-check via the Shared MarketingAudiencePort (lead marketing_consent /
 *                    user_consents). No positive consent -> skipped, never sent.
 *   2. SUPPRESSION   the tenant's unsubscribe list for this (email, category). Suppressed -> skipped.
 *   3. QUIET HOURS   inside the recipient's window -> DEFERRED to the window end (not dropped).
 *
 * Transactional/critical categories bypass all three and send immediately — that bypass is expressed
 * once, at the top of send(). Delivery itself is a Fake provider (no real ESP/SMS in this build).
 */
class MarketingDispatcher extends BaseService
{
    public function __construct(
        private readonly MarketingAudiencePort $audience,
        private readonly TemplateRenderer $renderer,
        private readonly QuietHoursCalculator $quietHours,
        private readonly UnsubscribeLinkGenerator $unsubscribeLinks,
        private readonly MailProvider $mail,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function send(
        int|string|null $organizationId,
        MarketingRecipient $recipient,
        NotificationCategory $category,
        string $templateKey,
        Channel $channel,
        array $data = [],
    ): MarketingSendResult {
        // Transactional bypass: consent, suppression and quiet hours apply to marketing ONLY.
        if ($category->isTransactional()) {
            $this->deliver($organizationId, $recipient, $templateKey, $channel, $category, $data);

            return MarketingSendResult::sent();
        }

        // 1. Consent (authoritative, live) — honours a withdrawal made after enrollment.
        if (! $this->audience->hasMarketingConsent($recipient->recipientType, $recipient->recipientId)) {
            return MarketingSendResult::noConsent();
        }

        // 2. Suppression list (unsubscribe) for this tenant + email + category.
        if ($this->isSuppressed($organizationId, $recipient->email, $category->value)) {
            return MarketingSendResult::suppressed();
        }

        // 3. Quiet hours — defer to the window end rather than drop.
        $deferUntil = $this->quietHoursDeferral($recipient);
        if ($deferUntil !== null) {
            return MarketingSendResult::deferred($deferUntil);
        }

        $this->deliver($organizationId, $recipient, $templateKey, $channel, $category, $data);

        return MarketingSendResult::sent();
    }

    /** Whether the tenant has an active suppression for this email + category. */
    public function isSuppressed(int|string|null $organizationId, string $email, string $category): bool
    {
        $query = MarketingSuppression::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', $email)
            ->where('category', $category);

        $query = $organizationId === null
            ? $query->whereNull('organization_id')
            : $query->where('organization_id', $organizationId);

        return $query->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deliver(
        int|string|null $organizationId,
        MarketingRecipient $recipient,
        string $templateKey,
        Channel $channel,
        NotificationCategory $category,
        array $data,
    ): void {
        $locale = $recipient->locale ?? (string) config('notifications.locale.default', 'en');

        // Every marketing message carries a signed, single-purpose unsubscribe link. Transactional
        // messages (the bypass path) are never unsubscribable, so they get no link.
        if ($category->isMarketing()) {
            $data['unsubscribe_url'] = $this->unsubscribeLinks->for($recipient->email, $category->value, $organizationId);
        }

        $message = $this->renderer->render($templateKey, $channel, $locale, $data);

        // Only email has a (fake) transport wired for marketing in this build; other channels render
        // but no-op the send. Live ESP/SMS remain local-required.
        if ($channel === Channel::Email) {
            $this->mail->send($recipient->email, $message->subject, $message->body);
        }
    }

    /** Resolve the recipient's quiet-hours window and return the deferral instant, or null. */
    private function quietHoursDeferral(MarketingRecipient $recipient): ?\Carbon\CarbonInterface
    {
        [$enabled, $start, $end, $timezone] = $this->quietHoursWindow($recipient);

        if (! $enabled) {
            return null;
        }

        return $this->quietHours->deferralUntil(now(), $timezone, $start, $end);
    }

    /**
     * Per-user quiet hours override the tenant/config default. Leads (no user account) use the
     * platform default window in the recipient's timezone.
     *
     * @return array{0:bool,1:?string,2:?string,3:?string}
     */
    private function quietHoursWindow(MarketingRecipient $recipient): array
    {
        if ($recipient->recipientType === 'user') {
            $setting = UserNotificationSetting::query()
                ->where('user_id', $recipient->recipientId)
                ->first();

            if ($setting !== null && (bool) $setting->quiet_hours_enabled) {
                return [true, $setting->quiet_hours_start, $setting->quiet_hours_end, $setting->timezone ?? $recipient->timezone];
            }
        }

        $default = (array) config('notifications.marketing.quiet_hours', []);

        return [
            (bool) ($default['enabled'] ?? false),
            $default['start'] ?? null,
            $default['end'] ?? null,
            $recipient->timezone ?? (string) config('app.timezone', 'UTC'),
        ];
    }
}
