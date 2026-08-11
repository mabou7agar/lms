<?php

declare(strict_types=1);

namespace App\Platform\Search\Support;

use App\Platform\Shared\Text\ArabicTextNormalizer;

/**
 * Turns text into (a) the index-safe NORMALISED form used for storage + keyword matching, and (b)
 * the CANONICAL form used for embedding.
 *
 * The canonical form folds a phrase to its sorted, de-duplicated bag of normalised tokens. Under the
 * deterministic FAKE embedding provider (which hashes the exact input string), this is what lets the
 * semantic arm retrieve word-order paraphrases the keyword arm misses: "advanced python programming"
 * and "programming advanced python" canonicalise identically, so they embed to the SAME vector
 * (cosine 1), while a substring keyword match on the reordered query fails.
 *
 * With a REAL embedding model, canonicalisation is unnecessary (the model captures true paraphrase);
 * it is gated by config('search.embedding.canonicalize'), true by default for the fake-first path.
 */
final class TextCanonicalizer
{
    public function __construct(
        private readonly ArabicTextNormalizer $normalizer,
    ) {}

    /** Normalise (fold Arabic/case/digits/whitespace) for storage + keyword ILIKE. */
    public function normalize(string $text): string
    {
        return $this->normalizer->normalize($text);
    }

    /**
     * The text actually embedded. When canonicalisation is enabled, returns the sorted unique token
     * set of the normalised text; otherwise the plain normalised text.
     */
    public function forEmbedding(string $text): string
    {
        $normalized = $this->normalize($text);

        if (! (bool) config('search.embedding.canonicalize', true)) {
            return $this->clamp($normalized);
        }

        $tokens = array_values(array_filter(
            array_unique(preg_split('/\s+/u', $normalized) ?: []),
            static fn (string $t): bool => $t !== '',
        ));

        sort($tokens, SORT_STRING);

        return $this->clamp(implode(' ', $tokens));
    }

    private function clamp(string $text): string
    {
        $max = (int) config('search.embedding.max_chars', 4000);

        return $max > 0 ? mb_substr($text, 0, $max) : $text;
    }
}
