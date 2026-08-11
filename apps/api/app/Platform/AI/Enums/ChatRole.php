<?php

declare(strict_types=1);

namespace App\Platform\AI\Enums;

/**
 * The role of a single chat message in a provider-neutral conversation.
 */
enum ChatRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
}
