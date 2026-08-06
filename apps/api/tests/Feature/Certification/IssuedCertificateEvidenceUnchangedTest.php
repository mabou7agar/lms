<?php

use App\Domains\Catalog\Models\Course;
use App\Domains\Certification\Actions\GenerateCertificateAction;
use App\Domains\Certification\Models\CertificateTemplate;
use App\Domains\Certification\Services\SignatureService;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Localizing the certificate TEMPLATE (name_i18n / html_i18n) must not change ISSUED-certificate
 * evidence. A certificate issued against a fully localized, active template still carries an intact,
 * verifiable signed record: number, verification_code, and a signature_hash that verifies via the
 * existing SignatureService and detects tampering.
 */
beforeEach(function () {
    config([
        'shared.locales' => ['en', 'ar'],
        'shared.fallback_locale' => 'en',
        'shared.default_locale' => 'en',
    ]);
});

function localizedActiveTemplate(): CertificateTemplate
{
    return CertificateTemplate::factory()->create([
        'is_active' => true,
        'name_i18n' => ['en' => 'Default Certificate', 'ar' => 'شهادة افتراضية'],
        'html_i18n' => [
            'en' => '<div><h1>Certificate</h1><p>{{ holder_name }}</p></div>',
            'ar' => '<div dir="rtl"><h1>شهادة</h1><p>{{ holder_name }}</p></div>',
        ],
    ]);
}

it('issues a certificate with intact, verifiable signed evidence against a localized template', function () {
    localizedActiveTemplate();
    $user = User::factory()->create();
    $course = Course::factory()->published()->create();

    $cert = app(GenerateCertificateAction::class)->executeByUserId($user->id, $course);

    expect($cert->number)->toBeString()->toStartWith('CERT-')
        ->and($cert->verification_code)->toBeString()->not->toBe('')
        ->and($cert->signature_hash)->toBeString()->not->toBe('');

    // The recorded signature must verify against the certificate's identifying fields.
    expect(app(SignatureService::class)->verify($cert->fresh()))->toBeTrue();
});

it('keeps the signed evidence tamper-evident after localization', function () {
    localizedActiveTemplate();
    $cert = app(GenerateCertificateAction::class)->executeByUserId(
        User::factory()->create()->id,
        Course::factory()->published()->create(),
    );

    $svc = app(SignatureService::class);
    expect($svc->verify($cert->fresh()))->toBeTrue();

    // Altering an identifying fact must invalidate the signature.
    $cert->forceFill(['number' => 'TAMPERED'])->save();
    expect($svc->verify($cert->fresh()))->toBeFalse();
});
