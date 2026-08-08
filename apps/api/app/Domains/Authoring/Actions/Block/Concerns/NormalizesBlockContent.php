<?php

namespace App\Domains\Authoring\Actions\Block\Concerns;

use App\Platform\Shared\Html\HtmlSanitizer;

/**
 * C5 - Shared helpers for block-write actions: defense-in-depth HTML sanitization of every locale's
 * payload, and derivation of the legacy default-locale `payload` mirror kept for backfill/snapshot
 * readers that predate localization.
 */
trait NormalizesBlockContent
{
    abstract protected function sanitizer(): HtmlSanitizer;

    /**
     * Sanitize each locale's payload array (article html and any other HTML-bearing field) before
     * persistence, mirroring UpdateLessonAction's content handling.
     *
     * @param  array<string, mixed>|null  $map
     * @return array<string, mixed>|null
     */
    protected function sanitizeContent(?array $map): ?array
    {
        if ($map === null) {
            return null;
        }

        $out = [];
        foreach ($map as $locale => $payload) {
            $out[$locale] = is_array($payload) ? $this->sanitizer()->sanitizeArray($payload) : $payload;
        }

        return $out;
    }

    /**
     * The default-locale payload, used to keep the legacy `payload` column populated and meaningful.
     *
     * @param  array<string, mixed>|null  $map
     * @return array<string, mixed>|null
     */
    protected function defaultLocaleContent(?array $map): ?array
    {
        if ($map === null || $map === []) {
            return null;
        }

        $default = (string) config('shared.default_locale', 'en');
        $value = $map[$default] ?? reset($map);

        return is_array($value) ? $value : null;
    }
}
