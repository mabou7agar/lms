<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers;

use App\Platform\AI\AiProviderManager;
use App\Platform\AI\Contracts\ChatModel;
use App\Platform\AI\Data\ChatResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Enums\AiProvider;

/** Defers fail-closed provider validation until an AI operation is actually requested. */
final readonly class DeferredChatModel implements ChatModel
{
    public function __construct(private AiProviderManager $providers) {}

    public function chat(array $messages, ModelOptions $options): ChatResult
    {
        return $this->providers->chatModel()->chat($messages, $options);
    }

    public function provider(): AiProvider
    {
        return $this->providers->chatModel()->provider();
    }
}
