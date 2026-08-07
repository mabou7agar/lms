<?php

declare(strict_types=1);

namespace App\Platform\Shared\Search;

use App\Platform\Shared\Text\ArabicTextNormalizer;
use Illuminate\Database\Eloquent\Model;

/**
 * Opt-in maintenance of a denormalised, locale-folded `search_text` column for a model.
 *
 * The platform is bilingual and content lives in `{field}_i18n` JSON maps (Arabic + English) beside
 * a legacy scalar. A raw `ILIKE '%term%'` over the legacy scalar therefore never finds Arabic
 * content (the scalar mirrors the default locale) and never folds Arabic letter/diacritic variants.
 *
 * This trait keeps a single normalised blob of every searchable source (both the legacy scalar and
 * every locale of its `{field}_i18n` map), folded through the shared {@see ArabicTextNormalizer}, so
 * a normalised query matches regardless of locale, diacritics, alef/ya/ta-marbuta form, digit script
 * or case. It carries no query logic — searching is the caller's concern; this only keeps the index
 * column correct on every write.
 *
 * Declare the sources via `protected array $searchable = ['title', 'subtitle', 'description'];`
 * (base names; both the scalar and `{base}_i18n` are read). Override the column name with
 * `protected string $searchTextColumn = '...';` if it is not `search_text`.
 *
 * @mixin Model
 */
trait SearchableText
{
    public static function bootSearchableText(): void
    {
        static::saving(static function (Model $model): void {
            if (method_exists($model, 'refreshSearchText')) {
                $model->refreshSearchText();
            }
        });
    }

    /** Recompute the folded search blob from every declared source (scalar + all i18n locales). */
    public function refreshSearchText(): void
    {
        $normalizer = app(ArabicTextNormalizer::class);

        $parts = [];

        foreach ($this->searchableSources() as $base) {
            $scalar = $this->getAttribute($base);
            if (is_string($scalar) && $scalar !== '') {
                $parts[] = $scalar;
            }

            $map = $this->getAttribute($base.'_i18n');
            if (is_array($map)) {
                foreach ($map as $value) {
                    if (is_string($value) && $value !== '') {
                        $parts[] = $value;
                    }
                }
            }
        }

        $this->setAttribute(
            $this->searchTextColumn(),
            $normalizer->normalize(implode(' ', $parts)),
        );
    }

    /** @return array<int, string> */
    protected function searchableSources(): array
    {
        return property_exists($this, 'searchable') ? (array) $this->searchable : [];
    }

    protected function searchTextColumn(): string
    {
        return property_exists($this, 'searchTextColumn') ? $this->searchTextColumn : 'search_text';
    }
}
