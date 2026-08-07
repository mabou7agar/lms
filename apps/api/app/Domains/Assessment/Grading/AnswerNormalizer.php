<?php

namespace App\Domains\Assessment\Grading;

use App\Platform\Shared\Text\ArabicTextNormalizer;

/**
 * Normalises free-text answers before comparison.
 *
 * "Exact match" on raw strings fails learners for reasons that have nothing to do with knowledge —
 * a trailing space, a curly apostrophe, an Arabic أ typed as ا. The actual folding rules live in the
 * shared {@see ArabicTextNormalizer} so grading and catalogue search fold text identically and can
 * never drift apart; this class adds the assessment-specific "matches any accepted spelling" rule
 * on top.
 *
 * Authors can opt out per question via `config`: {"case_sensitive": true, "normalize_arabic": false}.
 */
class AnswerNormalizer
{
    public function __construct(
        private readonly ArabicTextNormalizer $normalizer = new ArabicTextNormalizer,
    ) {}

    public function normalize(string $value, bool $caseSensitive = false, bool $normalizeArabic = true): string
    {
        return $this->normalizer->normalize($value, $caseSensitive, $normalizeArabic);
    }

    /**
     * True when the learner's text matches ANY accepted value. Authors list accepted spellings as
     * separate options rather than relying on fuzzy matching, so the answer key stays explicit
     * and reviewable.
     *
     * @param  iterable<string>  $accepted
     */
    public function matchesAny(string $submitted, iterable $accepted, bool $caseSensitive = false, bool $normalizeArabic = true): bool
    {
        $needle = $this->normalize($submitted, $caseSensitive, $normalizeArabic);

        if ($needle === '') {
            return false;
        }

        foreach ($accepted as $candidate) {
            if ($this->normalize($candidate, $caseSensitive, $normalizeArabic) === $needle) {
                return true;
            }
        }

        return false;
    }
}
