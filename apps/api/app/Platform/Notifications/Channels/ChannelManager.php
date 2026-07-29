<?php

namespace App\Platform\Notifications\Channels;

use App\Platform\Notifications\Channels\Fake\FakeEmailChannel;
use App\Platform\Notifications\Channels\Fake\FakePushChannel;
use App\Platform\Notifications\Channels\Fake\FakeSmsChannel;
use App\Platform\Notifications\Contracts\NotificationChannel;
use App\Platform\Notifications\Enums\Channel;
use App\Platform\Notifications\Exceptions\ChannelNotSupportedException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the delivery channel implementation for a channel the dispatcher has already deemed
 * available. Email/SMS/Push channels delegate to a provider resolved by ProviderManager from config
 * (fake by default, real when configured) — so this is NOT a fake bypass: a channel only reaches
 * here when ChannelAvailabilityResolver confirmed a real provider is configured.
 *
 * WhatsApp and Webhooks are never available (no provider / deferred to ADR-16), so they never reach
 * this resolver in practice; they throw here as defence in depth so a misroute fails loudly rather
 * than silently reporting a message as sent.
 */
class ChannelManager
{
    public function __construct(private readonly Container $app) {}

    public function resolve(Channel $channel): NotificationChannel
    {
        return match ($channel) {
            Channel::InApp => $this->app->make(InAppChannel::class),
            Channel::Email => $this->app->make(FakeEmailChannel::class),
            Channel::Sms => $this->app->make(FakeSmsChannel::class),
            Channel::Push => $this->app->make(FakePushChannel::class),
            // Never deliverable in v1 — the availability resolver keeps them out of the queue.
            Channel::WhatsApp, Channel::Webhooks => throw new ChannelNotSupportedException,
        };
    }
}
