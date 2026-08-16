<?php

declare(strict_types=1);

use App\Contexts\Commerce\Enums\CompanyCertificateBranding;
use App\Contexts\Commerce\Models\CompanyEntitlement;
use App\Contexts\Commerce\Models\Product;
use App\Domains\Catalog\Models\Course;
use App\Domains\Certification\Actions\GenerateCertificateAction;
use App\Domains\Certification\Models\Certificate;
use App\Domains\Crm\Models\Organization;
use App\Platform\Branding\Models\OrganizationBrandSetting;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
require_once __DIR__.'/CertificateHelpers.php';

beforeEach(fn () => app(TenantContext::class)->forget());
afterEach(fn () => app(TenantContext::class)->forget());

// ── Individual purchase ──────────────────────────────────────────────────────────────────────────

it('issues a certificate with the expiry the product was sold with', function (): void {
    [$product, $course] = certificateProduct();
    $buyer = User::factory()->create();
    paidOrderFor($product, $buyer);

    $certificate = app(GenerateCertificateAction::class)->executeByUserId($buyer->id, $course);

    expect($certificate)->not->toBeNull()
        ->and($certificate->expires_at)->not->toBeNull()
        // Two years from issue, per the product's certificate policy.
        ->and($certificate->expires_at->toDateString())->toBe(now()->addYears(2)->toDateString())
        // An individual purchase carries platform branding and no company context.
        ->and($certificate->organization_id)->toBeNull()
        ->and($certificate->isCompanyBranded())->toBeFalse();
});

it('issues no certificate at all when the product excludes one', function (): void {
    [$product, $course] = certificateProduct(['certificate_enabled' => false]);
    $buyer = User::factory()->create();
    paidOrderFor($product, $buyer);

    $certificate = app(GenerateCertificateAction::class)->executeByUserId($buyer->id, $course);

    expect($certificate)->toBeNull()
        ->and(Certificate::where('user_id', $buyer->id)->exists())->toBeFalse();
});

it('leaves a course nobody sells with an unrestricted certificate', function (): void {
    $course = Course::factory()->published()->create();
    $learner = User::factory()->create();

    $certificate = app(GenerateCertificateAction::class)->executeByUserId($learner->id, $course);

    expect($certificate)->not->toBeNull()
        ->and($certificate->expires_at)->toBeNull()
        ->and($certificate->isCompanyBranded())->toBeFalse();
});

// ── Company seat ─────────────────────────────────────────────────────────────────────────────────

it('brands an employee certificate with the company the seat came from', function (): void {
    [$product, $course] = certificateProduct(['audience' => 'company']);
    $org = Organization::factory()->create(['name' => 'Northwind Trading']);
    OrganizationBrandSetting::create([
        'organization_id' => $org->id,
        'logos' => ['logo_light' => 'https://cdn.example.test/northwind.png'],
    ]);

    paidOrderFor($product, certificateCompanyOwner($org), $org);
    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();
    $employee = seatedEmployee($org, $entitlement, 'staff@northwind.test');

    $certificate = app(GenerateCertificateAction::class)->executeByUserId($employee->id, $course);

    expect($certificate)->not->toBeNull()
        ->and($certificate->organization_id)->toBe((int) $org->id)
        ->and($certificate->company_name)->toBe('Northwind Trading')
        ->and($certificate->company_logo_url)->toBe('https://cdn.example.test/northwind.png')
        ->and($certificate->branding_mode)->toBe('company_logo_and_helbaron')
        ->and($certificate->isCompanyBranded())->toBeTrue();
});

it('does not put a company logo on a certificate branded HElbaron-only', function (): void {
    [$product, $course] = certificateProduct([
        'audience' => 'company',
        'company_certificate_branding' => CompanyCertificateBranding::HelbaronOnly->value,
    ]);
    $org = Organization::factory()->create(['name' => 'Quiet Corp']);
    OrganizationBrandSetting::create([
        'organization_id' => $org->id,
        'logos' => ['logo_light' => 'https://cdn.example.test/quiet.png'],
    ]);

    paidOrderFor($product, certificateCompanyOwner($org), $org);
    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();
    $employee = seatedEmployee($org, $entitlement, 'staff@quiet.test');

    $certificate = app(GenerateCertificateAction::class)->executeByUserId($employee->id, $course);

    expect($certificate->company_logo_url)->toBeNull()
        ->and($certificate->isCompanyBranded())->toBeFalse();
});

it('keeps the certificate the company was sold even after the product drops it', function (): void {
    [$product, $course] = certificateProduct(['audience' => 'company']);
    $org = Organization::factory()->create();
    paidOrderFor($product, certificateCompanyOwner($org), $org);
    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();
    $employee = seatedEmployee($org, $entitlement, 'staff@snapshot.test');

    // The admin turns certificates off AFTER the company bought.
    $product->forceFill(['certificate_enabled' => false])->save();

    $certificate = app(GenerateCertificateAction::class)->executeByUserId($employee->id, $course);

    expect($certificate)->not->toBeNull()
        ->and($certificate->organization_id)->toBe((int) $org->id);
});

// ── Verification ─────────────────────────────────────────────────────────────────────────────────

it('exposes expiry and company context on the public verification', function (): void {
    [$product, $course] = certificateProduct(['audience' => 'company']);
    $org = Organization::factory()->create(['name' => 'Verified Co']);
    paidOrderFor($product, certificateCompanyOwner($org), $org);
    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();
    $employee = seatedEmployee($org, $entitlement, 'staff@verified.test');

    $certificate = app(GenerateCertificateAction::class)->executeByUserId($employee->id, $course);

    $this->getJson("/api/v1/certificates/verify/{$certificate->verification_code}")
        ->assertOk()
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.company_name', 'Verified Co')
        ->assertJsonStructure(['data' => ['expires_at', 'company_name', 'company_logo_url']]);
});

it('verifies a lapsed certificate as expired rather than revoked', function (): void {
    [$product, $course] = certificateProduct();
    $buyer = User::factory()->create();
    paidOrderFor($product, $buyer);

    $certificate = app(GenerateCertificateAction::class)->executeByUserId($buyer->id, $course);
    $certificate->forceFill(['expires_at' => now()->subDay()])->save();

    $this->getJson("/api/v1/certificates/verify/{$certificate->verification_code}")
        ->assertOk()
        ->assertJsonPath('data.status', 'expired')
        // Genuine, but no longer current.
        ->assertJsonPath('data.valid', false);
});

it('marks a lapsed certificate as expired in the learner list', function (): void {
    [$product, $course] = certificateProduct();
    $buyer = User::factory()->create();
    paidOrderFor($product, $buyer);

    $certificate = app(GenerateCertificateAction::class)->executeByUserId($buyer->id, $course);
    $certificate->forceFill(['expires_at' => now()->subDay()])->save();

    Sanctum::actingAs($buyer);

    $this->getJson('/api/v1/my-certificates')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'expired')
        ->assertJsonPath('data.0.expired', true);
});
