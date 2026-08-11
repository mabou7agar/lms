<?php

declare(strict_types=1);

namespace App\Platform\AI\Contracts;

use App\Platform\AI\Data\ChatMessage;
use App\Platform\AI\Data\ChatResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Enums\AiProvider;

/**
 * Provider-neutral chat completion port. Every backend (fake + real vendors) implements this, so
 * higher layers (AiClient, later the tutor/copilot) depend only on this shape — never on a vendor
 * SDK. Implementations MUST return token usage so metering/quotas can account for the call.
 */
interface ChatModel
{
    /**
     * @param  list<ChatMessage>  $messages
     */
    public function chat(array $messages, ModelOptions $options): ChatResult;

    /** Which provider backs this model (for attribution/metering). */
    public function provider(): AiProvider;
}
