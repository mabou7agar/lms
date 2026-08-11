<?php

namespace App\Platform\Notifications\Enums;

enum AutomationActionType: string
{
    // The allow-listed, safe automation actions. New action types must be added here AND handled in
    // the AutomationRunner's match — an unknown action_type is ignored, never executed.
    case SendNotification = 'send_notification';
    case EnqueueCampaign = 'enqueue_campaign';
    case TagLead = 'tag_lead';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
