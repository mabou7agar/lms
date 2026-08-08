<?php

use App\Domains\Catalog\Models\Course;
use App\Domains\Certification\Actions\GenerateCertificateAction;
use App\Domains\Certification\Models\CertificateTemplate;
use App\Domains\Certification\Services\CertificateRenderService;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['shared.locales' => ['en', 'ar'], 'shared.fallback_locale' => 'en', 'shared.default_locale' => 'en', 'shared.rtl_locales' => ['ar']]);
});

it('honors a portrait template orientation through the render pipeline', function () {
    CertificateTemplate::factory()->create([
        'is_active' => true,
        'orientation' => 'portrait',
        'html_i18n' => ['en' => '<div>Certificate for {{ holder_name }}</div>'],
    ]);

    $cert = app(GenerateCertificateAction::class)->executeByUserId(
        User::factory()->create()->id,
        Course::factory()->published()->create(),
    );

    // The FakePdfGenerator records the requested orientation in the byte stream.
    expect(app(CertificateRenderService::class)->renderBytes($cert))->toContain('orientation=portrait');
});

it('renders the Arabic body with dir=rtl when the active locale is Arabic', function () {
    CertificateTemplate::factory()->create([
        'is_active' => true,
        'html_i18n' => [
            'en' => '<div>Certificate for {{ holder_name }}</div>',
            'ar' => '<div>شهادة إلى {{ holder_name }}</div>',
        ],
    ]);

    $cert = app(GenerateCertificateAction::class)->executeByUserId(
        User::factory()->create()->id,
        Course::factory()->published()->create(),
    );

    app()->setLocale('ar');

    $bytes = app(CertificateRenderService::class)->renderBytes($cert);

    expect($bytes)->toContain('شهادة')->toContain('dir=rtl');
});
