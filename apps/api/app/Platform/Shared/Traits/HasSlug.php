<?php

namespace App\Platform\Shared\Traits;

use App\Platform\Shared\Helpers\Slug;

/**
 * Auto-generates a URL slug from a source attribute (default `name`) into a `slug` column
 * when the slug is empty. Override slugSource()/slugColumn() to customize. No business logic.
 */
trait HasSlug
{
    public static function bootHasSlug(): void
    {
        static::saving(function ($model): void {
            $column = $model->slugColumn();

            if (empty($model->{$column})) {
                $sourceAttribute = $model->slugSource();
                $source = (string) ($model->{$sourceAttribute} ?? '');

                // Translation-backed forms commonly submit `{source}_i18n` without the legacy
                // scalar. Trait boot order is not a safe contract between HasSlug and
                // HasTranslations, so resolve the configured/default locale directly when the
                // scalar has not yet been synchronized.
                if ($source === '') {
                    $translations = $model->getAttribute($sourceAttribute.'_i18n');

                    if (is_array($translations) && $translations !== []) {
                        $default = (string) config('shared.default_locale', 'en');
                        $translated = $translations[$default] ?? reset($translations);
                        $source = is_string($translated) ? $translated : '';
                    }
                }

                if ($source !== '') {
                    $model->{$column} = Slug::make($source);
                }
            }
        });
    }

    public function slugSource(): string
    {
        return 'name';
    }

    public function slugColumn(): string
    {
        return 'slug';
    }
}
