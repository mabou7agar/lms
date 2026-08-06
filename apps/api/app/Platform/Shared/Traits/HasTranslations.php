<?php

namespace App\Platform\Shared\Traits;

use App\Platform\Shared\Helpers\LocaleHelper;
use App\Platform\Shared\Html\HtmlSanitizer;
use App\Platform\Shared\I18n\TranslationResolver;
use App\Platform\Shared\I18n\UnsupportedLocaleException;

/**
 * Lightweight JSON translations for model attributes. Translatable attributes are stored as
 * JSON maps of locale => value. Declare which attributes are translatable via
 * `protected array $translatable = ['title_i18n', 'description_i18n'];` and cast them to 'array'.
 * Declare which of those hold rich HTML via `protected array $translatableHtml = ['html_i18n'];`
 * and every locale value is sanitized through the shared HtmlSanitizer on write.
 *
 * Resolution (locale/fallback/empty/null) is delegated to the central TranslationResolver — this
 * trait carries no fallback logic of its own.
 */
trait HasTranslations
{
    /**
     * On every write: (1) sanitize each declared HTML translatable map per locale, then (2) keep the
     * legacy scalar column in sync with the default-locale value of its `{base}_i18n` map. Order
     * matters — sanitising first means the legacy scalar is synced from the already-sanitised value.
     * Syncing preserves NOT NULL constraints and any not-yet-migrated readers during the expand ->
     * migrate -> contract window, and is a no-op for rows that only set the legacy scalar (existing
     * factories/seeders), so it never disturbs pre-localization behaviour.
     */
    public static function bootHasTranslations(): void
    {
        static::saving(function (self $model): void {
            $model->sanitizeTranslatableHtml();
            $model->syncTranslatableLegacyColumns();
        });
    }

    /** Get a translated value for the given (or active) locale via the central resolver. */
    public function translate(string $attribute, ?string $locale = null): mixed
    {
        return app(TranslationResolver::class)->resolve($this->{$attribute}, $locale);
    }

    /**
     * Resolve a localized value from the `{base}_i18n` JSON map, falling back to the legacy scalar
     * `{base}` column while the expand -> migrate -> contract window is open (before the legacy
     * column is dropped). Keeps string-out API resources backward compatible for rows/factories
     * that only populated the legacy scalar.
     */
    public function localized(string $baseAttribute, ?string $locale = null): mixed
    {
        $translated = $this->translate($baseAttribute.'_i18n', $locale);

        if ($translated !== null && $translated !== '') {
            return $translated;
        }

        return $this->{$baseAttribute} ?? null;
    }

    /**
     * Set a translation for a single supported locale without dropping the others.
     *
     * @throws UnsupportedLocaleException when $locale is outside the supported allowlist
     */
    public function setTranslation(string $attribute, string $locale, mixed $value): static
    {
        if (! in_array($locale, LocaleHelper::supported(), true)) {
            throw new UnsupportedLocaleException($locale);
        }

        $current = is_array($this->{$attribute}) ? $this->{$attribute} : [];
        $current[$locale] = $value;
        $this->{$attribute} = $current;

        return $this;
    }

    /** @return array<int, string> */
    public function translatableAttributes(): array
    {
        return property_exists($this, 'translatable') ? (array) $this->translatable : [];
    }

    /** @return array<int, string> */
    public function translatableHtmlAttributes(): array
    {
        return property_exists($this, 'translatableHtml') ? (array) $this->translatableHtml : [];
    }

    /** Sanitize every locale value of each declared HTML translatable map in-place. */
    private function sanitizeTranslatableHtml(): void
    {
        $htmlAttributes = $this->translatableHtmlAttributes();

        if ($htmlAttributes === []) {
            return;
        }

        $sanitizer = app(HtmlSanitizer::class);

        foreach ($htmlAttributes as $attribute) {
            $map = $this->getAttribute($attribute);

            if (! is_array($map)) {
                continue;
            }

            foreach ($map as $locale => $value) {
                if (is_string($value)) {
                    $map[$locale] = $sanitizer->sanitize($value);
                }
            }

            $this->setAttribute($attribute, $map);
        }
    }

    /** Sync each legacy scalar column from the default-locale value of its `{base}_i18n` map. */
    private function syncTranslatableLegacyColumns(): void
    {
        $default = (string) config('shared.default_locale', 'en');

        foreach ($this->translatableAttributes() as $attribute) {
            if (! str_ends_with($attribute, '_i18n')) {
                continue;
            }

            $map = $this->getAttribute($attribute);

            if (! is_array($map) || $map === []) {
                continue;
            }

            $base = substr($attribute, 0, -5);

            if (! $this->isFillable($base) && ! array_key_exists($base, $this->getAttributes())) {
                continue;
            }

            $value = $map[$default] ?? reset($map);

            if (is_string($value) && $value !== '') {
                $this->setAttribute($base, $value);
            }
        }
    }
}
