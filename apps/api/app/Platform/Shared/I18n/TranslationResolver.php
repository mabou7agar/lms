<?php

declare(strict_types=1);

namespace App\Platform\Shared\I18n;

use App\Platform\Shared\Helpers\LocaleHelper;

/**
 * Central resolution of a translatable value (a `locale => value` JSON map) down to a single
 * value for a target locale. This is the ONE place fallback/empty/null rules live — models call
 * HasTranslations::translate(), which delegates here, so no per-model fallback logic exists.
 *
 * Rules (deterministic):
 *   1. requested locale (defaults to the active app locale)
 *   2. application fallback locale (config/shared.php)
 *   3. first non-empty translation in the map
 *   4. null
 * An empty string (or empty array) is treated as ABSENT so it never masks a valid fallback.
 * A non-array value (legacy scalar, pre-migration) passes through unchanged.
 */
final class TranslationResolver
{
    public function resolve(mixed $value, ?string $locale = null): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $locale ??= LocaleHelper::current();

        return $this->pick($value, $locale)
            ?? $this->pick($value, LocaleHelper::fallback())
            ?? $this->firstNonEmpty($value);
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function pick(array $map, string $locale): mixed
    {
        $candidate = $map[$locale] ?? null;

        return $this->isEmpty($candidate) ? null : $candidate;
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function firstNonEmpty(array $map): mixed
    {
        foreach ($map as $candidate) {
            if (! $this->isEmpty($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
