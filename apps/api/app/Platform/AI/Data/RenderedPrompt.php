<?php

declare(strict_types=1);

namespace App\Platform\AI\Data;

use App\Platform\AI\Enums\ChatRole;

/**
 * A fully-resolved, variable-interpolated prompt at a specific immutable version. The version is
 * carried through to the usage row so an AI run records EXACTLY the prompt text that produced it,
 * even after the prompt is later edited or rolled back.
 */
final class RenderedPrompt
{
    public function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly string $locale,
        public readonly string $systemPrompt,
        public readonly string $userPrompt,
        public readonly ?string $modelPreference = null,
    ) {}

    /**
     * The conversation to send to a ChatModel: system message first (when non-empty), then user.
     *
     * @return list<ChatMessage>
     */
    public function toMessages(): array
    {
        $messages = [];

        if (trim($this->systemPrompt) !== '') {
            $messages[] = new ChatMessage(ChatRole::System, $this->systemPrompt);
        }

        $messages[] = new ChatMessage(ChatRole::User, $this->userPrompt);

        return $messages;
    }
}
