<?php

use App\Domains\Catalog\Models\Course;
use App\Domains\Certification\Actions\GenerateCertificateAction;
use App\Domains\Certification\Models\CertificateTemplate;
use App\Domains\Certification\Services\CertificateRenderService;
use App\Domains\Certification\Services\SignatureService;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Template versioning / visual immutability. An issued certificate renders from the frozen snapshot
 * captured at issuance; editing the template later never changes the issued document, while a newly
 * issued certificate reflects the edit. The signed evidence is untouched throughout.
 */
beforeEach(function () {
    config(['shared.locales' => ['en', 'ar'], 'shared.fallback_locale' => 'en', 'shared.default_locale' => 'en']);
});

it('freezes an issued certificate against later template edits, while new certs reflect the edit', function () {
    $template = CertificateTemplate::factory()->create([
        'is_active' => true,
        'html_i18n' => ['en' => '<div>Body ONE for {{ holder_name }}</div>'],
    ]);

    $cert = app(GenerateCertificateAction::class)->executeByUserId(
        User::factory()->create(['name' => 'Snap User'])->id,
        Course::factory()->published()->create(),
    );

    $renderer = app(CertificateRenderService::class);

    expect($renderer->renderBytes($cert))->toContain('Body ONE');

    // Edit the LIVE template after issuance.
    $template->update(['html_i18n' => ['en' => '<div>Body TWO for {{ holder_name }}</div>']]);

    // The already-issued certificate is unchanged (renders from its snapshot).
    expect($renderer->renderBytes($cert->fresh()))
        ->toContain('Body ONE')
        ->not->toContain('Body TWO');

    // A newly issued certificate reflects the edit.
    $cert2 = app(GenerateCertificateAction::class)->executeByUserId(
        User::factory()->create()->id,
        Course::factory()->published()->create(),
    );

    expect($renderer->renderBytes($cert2))->toContain('Body TWO');

    // Signed evidence remains intact after everything.
    expect(app(SignatureService::class)->verify($cert->fresh()))->toBeTrue();
});

it('records the template version on the issued certificate', function () {
    CertificateTemplate::factory()->create(['is_active' => true, 'version' => 3]);

    $cert = app(GenerateCertificateAction::class)->executeByUserId(
        User::factory()->create()->id,
        Course::factory()->published()->create(),
    );

    expect($cert->template_version)->toBe(3)
        ->and($cert->rendered_snapshot)->toBeArray()
        ->and($cert->rendered_snapshot['version'])->toBe(3);
});
