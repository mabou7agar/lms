<?php

namespace App\Platform\Notifications\Channels\Fake;

use App\Platform\Notifications\Contracts\NotificationChannel;
use App\Platform\Notifications\Contracts\Providers\MailProvider;
use App\Platform\Notifications\Data\RenderedMessage;
use App\Platform\Notifications\Models\NotificationDelivery;

class FakeEmailChannel implements NotificationChannel
{
    public function __construct(private readonly MailProvider $mail) {}

    public function send(NotificationDelivery $delivery, RenderedMessage $message): void
    {
        // The recipient is the Identity user resolved off the notification. Notifications keeps no
        // compile-time dependency on the concrete User model (the relation is typed as base Model),
        // so narrow to the routing shape this channel actually needs.
        /** @var object{email?: string|null}|null $recipient */
        $recipient = $delivery->notification->user;
        $to = (string) ($recipient?->email ?? '');
        $this->mail->send($to, $message->subject, $message->body);
    }
}
