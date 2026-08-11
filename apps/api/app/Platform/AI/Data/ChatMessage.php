<?php

declare(strict_types=1);

namespace App\Platform\AI\Data;

use App\Platform\AI\Enums\ChatRole;

/**
 * One message in a provider-neutral chat conversation. Adapters translate a list of these into
 * whatever wire shape their vendor expects.
 */
final class ChatMessage
{
    public function __construct(
        public readonly ChatRole $role,
        public readonly string $content,
    ) {}

    public static function system(string $content): self
    {
        return new self(ChatRole::System, $content);
    }

    public static function user(string $content): self
    {
        return new self(ChatRole::User, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(ChatRole::Assistant, $content);
    }

    /** @return array{role: string, content: string} */
    public function toArray(): array
    {
        return ['role' => $this->role->value, 'content' => $this->content];
    }
}
