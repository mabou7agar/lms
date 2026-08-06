<?php

use App\Platform\Shared\I18n\TranslationResolver;

beforeEach(function () {
    config(['shared.fallback_locale' => 'en']);
    $this->resolver = new TranslationResolver();
});

it('returns the value for the requested locale', function () {
    expect($this->resolver->resolve(['en' => 'Hello', 'ar' => 'مرحبا'], 'ar'))->toBe('مرحبا');
});

it('falls back to the app fallback locale when the requested locale is missing', function () {
    expect($this->resolver->resolve(['en' => 'Hello'], 'ar'))->toBe('Hello');
});

it('treats an empty-string translation as absent and uses the fallback', function () {
    expect($this->resolver->resolve(['en' => 'Hello', 'ar' => ''], 'ar'))->toBe('Hello');
});

it('treats a null translation as absent and uses the fallback', function () {
    expect($this->resolver->resolve(['en' => 'Hello', 'ar' => null], 'ar'))->toBe('Hello');
});

it('uses the first non-empty translation when neither requested nor fallback exist', function () {
    expect($this->resolver->resolve(['fr' => 'Bonjour'], 'ar'))->toBe('Bonjour');
});

it('passes a non-array legacy scalar through unchanged', function () {
    expect($this->resolver->resolve('Legacy title', 'ar'))->toBe('Legacy title');
});

it('returns null for an all-empty translation map', function () {
    expect($this->resolver->resolve(['en' => '', 'ar' => null], 'ar'))->toBeNull();
});

it('uses the active app locale when no locale is passed', function () {
    app()->setLocale('ar');
    expect($this->resolver->resolve(['en' => 'Hello', 'ar' => 'مرحبا']))->toBe('مرحبا');
});
