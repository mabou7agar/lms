<?php

declare(strict_types=1);

namespace App\Platform\AI\Governance;

/**
 * A basic, deterministic prompt-injection input guard. It is NOT a complete defence (no such thing
 * exists) — it strips control characters and neutralizes the most common override phrasings
 * ("ignore previous instructions", fake "system:" turns, etc.) in UNTRUSTED user-supplied variable
 * values before they are interpolated into a prompt. System/user templates authored in the library
 * are trusted and are never passed through here.
 */
final class PromptInjectionGuard
{
    /** @var list<string> */
    private const SUSPICIOUS_PATTERNS = [
        '/ignore\s+(all\s+)?(previous|prior|above)\s+(instructions|prompts|messages)/i',
        '/disregard\s+(the\s+)?(previous|prior|above|system)/i',
        '/forget\s+(everything|all\s+previous|your\s+instructions)/i',
        '/you\s+are\s+now\s+(a|an|no longer)/i',
        '/^\s*(system|assistant)\s*:/im',
        '/reveal\s+(your\s+)?(system\s+prompt|instructions|hidden)/i',
        '/override\s+(your\s+)?(instructions|rules|guardrails)/i',
    ];

    /** Neutralize an untrusted value: strip control chars, collapse, and defuse override phrasings. */
    public function sanitize(string $input): string
    {
        // Remove control characters except tab/newline.
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);

        // Defuse recognised injection phrasings by bracketing them so they read as inert text.
        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            $clean = (string) preg_replace_callback(
                $pattern,
                static fn (array $m): string => '[filtered: '.trim((string) $m[0]).']',
                $clean,
            );
        }

        return trim($clean);
    }

    /** True when the input matches a known injection pattern (for logging/telemetry). */
    public function isSuspicious(string $input): bool
    {
        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $input) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize every value in a variable map (keys untouched).
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function sanitizeVariables(array $variables): array
    {
        $out = [];

        foreach ($variables as $key => $value) {
            $out[$key] = is_string($value) ? $this->sanitize($value) : $value;
        }

        return $out;
    }
}
