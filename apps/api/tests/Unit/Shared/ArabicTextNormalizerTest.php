<?php

use App\Platform\Shared\Text\ArabicTextNormalizer;

beforeEach(function (): void {
    $this->normalizer = new ArabicTextNormalizer;
});

it('folds alef variants onto a bare alef', function (): void {
    // آ أ إ ٱ all collapse to ا, so the same word typed with any hamza form matches.
    expect($this->normalizer->normalize('أحمد'))->toBe($this->normalizer->normalize('احمد'))
        ->and($this->normalizer->normalize('إسلام'))->toBe($this->normalizer->normalize('اسلام'))
        ->and($this->normalizer->normalize('آمنة'))->toBe($this->normalizer->normalize('امنه'));
});

it('folds ya (ى) to ي and ta marbuta (ة) to ه', function (): void {
    expect($this->normalizer->normalize('مصطفى'))->toBe($this->normalizer->normalize('مصطفي'))
        ->and($this->normalizer->normalize('مدرسة'))->toBe($this->normalizer->normalize('مدرسه'));
});

it('strips harakat and tatweel', function (): void {
    expect($this->normalizer->normalize('مُحَمَّد'))->toBe($this->normalizer->normalize('محمد'))
        ->and($this->normalizer->normalize('كتـــاب'))->toBe($this->normalizer->normalize('كتاب'));
});

it('maps Arabic-Indic and Eastern Arabic-Indic digits onto ASCII', function (): void {
    expect($this->normalizer->normalize('٤٢'))->toBe('42')
        ->and($this->normalizer->normalize('۴۲'))->toBe('42');
});

it('collapses whitespace including non-breaking spaces and trims', function (): void {
    expect($this->normalizer->normalize("  hello \u{00A0}  world  "))->toBe('hello world');
});

it('lowercases by default and preserves case when asked', function (): void {
    expect($this->normalizer->normalize('Laravel MASTERY'))->toBe('laravel mastery')
        ->and($this->normalizer->normalize('Laravel MASTERY', caseSensitive: true))->toBe('Laravel MASTERY');
});

it('folds typographic quotes and dashes to ascii', function (): void {
    expect($this->normalizer->normalize("it\u{2019}s a\u{2014}b"))->toBe("it's a-b");
});

it('leaves Arabic letters intact when Arabic normalisation is disabled', function (): void {
    // Digits/whitespace still fold, but أ stays distinct from ا.
    expect($this->normalizer->normalize('أحمد', normalizeArabic: false))
        ->not->toBe($this->normalizer->normalize('احمد', normalizeArabic: false));
});
