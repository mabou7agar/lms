<?php

declare(strict_types=1);

namespace App\Platform\AI\Support;

use App\Platform\AI\Data\ChatMessage;

/**
 * Deterministic, network-free token estimation used for PRE-call quota checks (a real vendor only
 * reports authoritative usage AFTER the call). Uses the widely-used ~4-characters-per-token
 * heuristic; it is intentionally an over/under approximation, never billed — metering always
 * records the provider's returned usage, this only guards the gate.
 */
final class TokenEstimator
{
    private const CHARS_PER_TOKEN = 4;

    public function estimate(string $text): int
    {
        $length = mb_strlen(trim($text));

        if ($length === 0) {
            return 0;
        }

        return (int) max(1, (int) ceil($length / self::CHARS_PER_TOKEN));
    }

    /**
     * @param  list<ChatMessage>  $messages
     */
    public function estimateMessages(array $messages): int
    {
        $total = 0;

        foreach ($messages as $message) {
            // + a few tokens per message for role/formatting overhead, mirroring vendor tokenizers.
            $total += $this->estimate($message->content) + 4;
        }

        return $total;
    }
}
