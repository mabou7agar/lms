<?php

declare(strict_types=1);

namespace App\Platform\AI\Metering;

use App\Platform\AI\Data\TokenUsage;
use App\Platform\AI\Enums\AiProvider;

/**
 * Turns token usage into an estimated cost in USD micros (1e-6 USD), using the per-provider,
 * per-model rate table in config('ai.pricing'). Rates are micros per 1,000 tokens (input/output
 * priced separately). Unknown models cost 0 — the platform never invents a price it wasn't told.
 * Integer micros throughout so totals are exact and DB-friendly (no float drift).
 */
final class CostCalculator
{
    public function micros(AiProvider $provider, string $model, TokenUsage $usage): int
    {
        /** @var array<string, mixed> $rates */
        $rates = (array) config("ai.pricing.{$provider->value}.{$model}", []);

        $inputRate = (float) ($rates['input'] ?? 0);   // micros per 1K input tokens
        $outputRate = (float) ($rates['output'] ?? 0); // micros per 1K output tokens

        $cost = ($usage->inputTokens / 1000.0) * $inputRate
            + ($usage->outputTokens / 1000.0) * $outputRate;

        return (int) round($cost);
    }
}
