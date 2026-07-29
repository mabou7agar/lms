<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Notifications\Enums\Channel;
use App\Platform\Notifications\Enums\ChannelAvailability;
use App\Platform\Shared\Services\BaseService;

/**
 * The single place that decides whether a channel may deliver — the whole of the H6 gating rule.
 *
 * Two independent switches per channel:
 *   1. A per-channel enable toggle in config (operator on/off), so channels are independently
 *      configurable via env — see config/notifications.php `channels`.
 *   2. Whether a real provider is actually configured with working credentials.
 *
 * Enablement is read from this module's own config rather than the platform feature-flag service on
 * purpose: Notifications may depend only on Shared + Identity contracts (Deptrac), and reaching into
 * the Features module would be a new boundary violation. Config gives the same per-channel on/off
 * without the coupling.
 *
 * The v1 policy (product decision):
 *   In-App     always Available — the notification row IS the delivery.
 *   Email/SMS  disabled → Disabled; enabled + provider configured → Available; enabled + not
 *              configured → Misconfigured (a required channel with no provider is a config error).
 *   WhatsApp   disabled OR no provider → Disabled (there is no WhatsApp provider yet). Never faked.
 *   Push       disabled OR no provider → Disabled; configured → Available.
 *   Webhooks   always Disabled — registered for contract completeness, transport deferred to ADR-16.
 *
 * "Configured" deliberately excludes the fake providers: a fake provider must never let a channel
 * report itself deliverable, because that is exactly how a ledger comes to claim a message was sent
 * that never left the building.
 */
class ChannelAvailabilityResolver extends BaseService
{
    public function for(Channel $channel): ChannelAvailability
    {
        return match ($channel) {
            Channel::InApp => ChannelAvailability::Available,
            Channel::Email => $this->gate('email', $this->mailConfigured(), required: true),
            Channel::Sms => $this->gate('sms', $this->smsConfigured(), required: true),
            Channel::WhatsApp => $this->gate('whatsapp', $this->whatsAppConfigured(), required: false),
            Channel::Push => $this->gate('push', $this->pushConfigured(), required: false),
            // ADR-16: registered but never sends. Not toggle-driven — hard off so it can never deliver.
            Channel::Webhooks => ChannelAvailability::Disabled,
        };
    }

    private function gate(string $key, bool $providerConfigured, bool $required): ChannelAvailability
    {
        if (! (bool) config("notifications.channels.{$key}.enabled", true)) {
            return ChannelAvailability::Disabled; // operator turned the channel off
        }

        if ($providerConfigured) {
            return ChannelAvailability::Available;
        }

        // Enabled but no working provider: a config error for required channels, an expected
        // non-send for optional ones.
        return $required ? ChannelAvailability::Misconfigured : ChannelAvailability::Disabled;
    }

    private function mailConfigured(): bool
    {
        return config('notifications.providers.mail') === 'mailgun'
            && filled(config('services.mailgun.domain'))
            && filled(config('services.mailgun.secret'));
    }

    private function smsConfigured(): bool
    {
        return config('notifications.providers.sms') === 'twilio'
            && filled(config('services.twilio.account_sid'))
            && filled(config('services.twilio.auth_token'));
    }

    private function pushConfigured(): bool
    {
        return config('notifications.providers.push') === 'firebase'
            && filled(config('services.firebase.server_key'));
    }

    /** No WhatsApp provider exists yet, so WhatsApp is never configured — always Skipped (Disabled). */
    private function whatsAppConfigured(): bool
    {
        return false;
    }
}
