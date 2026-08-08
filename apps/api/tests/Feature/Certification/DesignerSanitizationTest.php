<?php

use App\Domains\Certification\Models\CertificateTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The designer must not be able to emit unsafe HTML. The template body (html_i18n) is sanitized
 * through the shared HtmlSanitizer on save (via HasTranslations), so a script tag, a javascript:
 * URL, and event handlers are stripped while safe markup and http(s) images survive.
 */
beforeEach(function () {
    config(['shared.locales' => ['en', 'ar'], 'shared.fallback_locale' => 'en', 'shared.default_locale' => 'en']);
});

it('strips script tags and event handlers from a saved certificate template body', function () {
    $template = CertificateTemplate::factory()->create([
        'html_i18n' => [
            'en' => '<p>Award <strong>Winner</strong></p>'
                .'<script>alert(1)</script>'
                .'<a href="javascript:alert(1)" onclick="steal()">bad</a>'
                .'<img src="https://cdn.example.com/logo.png" alt="ok" onerror="pwn()">',
        ],
    ]);

    $en = $template->refresh()->html_i18n['en'];

    expect($en)
        ->toContain('<strong>Winner</strong>')
        ->toContain('https://cdn.example.com/logo.png')
        ->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('onerror')
        ->not->toContain('javascript:');
});
