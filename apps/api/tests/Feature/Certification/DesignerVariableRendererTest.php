<?php

use App\Domains\Catalog\Models\Course;
use App\Domains\Certification\Models\Certificate;
use App\Domains\Certification\Services\CertificateVariableRenderer;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The constrained, safe variable renderer: allowlisted tokens resolve (legacy + new aliases),
 * unknown tokens vanish, text is HTML-escaped, and trusted image/QR markup is injected verbatim.
 */
function makeCert(array $overrides = []): Certificate
{
    $user = User::factory()->create(['name' => $overrides['name'] ?? 'Alice Learner']);
    $course = Course::factory()->published()->create(['title' => $overrides['title'] ?? 'Safe Course']);

    return Certificate::factory()->create(array_merge([
        'user_id' => $user->id,
        'course_id' => $course->id,
        'number' => 'CERT-2026-000123',
        'verification_code' => 'ABC123XYZ',
    ], $overrides['cert'] ?? []));
}

it('resolves every allowlisted variable, including trusted QR markup', function () {
    $cert = makeCert();
    $body = '{{ holder_name }}|{{ course_title }}|{{ number }}|{{ verification_code }}|{{ verify_url }}|{{ issued_at }}|{{ qr_svg }}';

    $out = app(CertificateVariableRenderer::class)->render($body, $cert);

    expect($out)->toContain('Alice Learner')
        ->toContain('Safe Course')
        ->toContain('CERT-2026-000123')
        ->toContain('ABC123XYZ')
        ->toContain('verify/ABC123XYZ')
        ->toContain('<svg'); // QR is trusted server markup, not escaped
});

it('supports both legacy and new token aliases', function () {
    $cert = makeCert();
    $renderer = app(CertificateVariableRenderer::class);

    $legacy = $renderer->render('{{ holder_name }} {{ number }} {{ verify_url }} {{ qr_svg }} {{ issued_at }}', $cert);
    $modern = $renderer->render('{{ learner_name }} {{ certificate_number }} {{ verification_url }} {{ qr_code }} {{ issued_date }}', $cert);

    expect($legacy)->toContain('Alice Learner')->toContain('CERT-2026-000123')->toContain('<svg');
    expect($modern)->toContain('Alice Learner')->toContain('CERT-2026-000123')->toContain('<svg');
});

it('renders an unknown or unsupported token as an empty string (never leaks the literal)', function () {
    $out = app(CertificateVariableRenderer::class)->render('X{{ totally_unknown }}Y{{ another_bogus_one }}Z', makeCert());

    expect($out)->toBe('XYZ')
        ->not->toContain('totally_unknown')
        ->not->toContain('{{');
});

it('renders a null/absent score as blank', function () {
    $out = app(CertificateVariableRenderer::class)->render('[{{ score }}]', makeCert());

    expect($out)->toBe('[]');
});

it('resolves score and instructor names from the certificate metadata snapshot', function () {
    $cert = makeCert();
    $cert->forceFill(['metadata' => ['score' => '88%', 'instructor_names' => ['Dr. A', 'Prof. B']]])->save();

    $out = app(CertificateVariableRenderer::class)->render('{{ score }} :: {{ instructor_names }}', $cert->fresh());

    expect($out)->toContain('88%')->toContain('Dr. A, Prof. B');
});

it('HTML-escapes text values so a malicious course title cannot inject markup', function () {
    $cert = makeCert(['title' => '<script>alert(1)</script>Evil']);

    $out = app(CertificateVariableRenderer::class)->render('{{ course_title }}', $cert);

    expect($out)->not->toContain('<script>')
        ->toContain('&lt;script&gt;');
});

it('renders a sample document for preview through the same allowlist path', function () {
    $out = app(CertificateVariableRenderer::class)->renderSample('{{ holder_name }} — {{ course_title }} {{ qr_code }}');

    expect($out)->toContain('Sample Learner')->toContain('<svg')->not->toContain('{{');
});
