<?php

namespace App\Domains\Certification\Services;

use App\Domains\Certification\Models\Certificate;
use App\Platform\Shared\Services\BaseService;

/**
 * Public verification: resolves a certificate by code and returns only non-sensitive issuance
 * facts (no ids, no storage paths).
 */
class CertificateVerificationService extends BaseService
{
    public function __construct(private readonly SignatureService $signatures) {}

    /** @return array<string, mixed>|null */
    public function verify(string $code): ?array
    {
        $certificate = Certificate::query()
            ->with(['user', 'course'])
            ->where('verification_code', $code)
            ->first();

        if ($certificate === null) {
            return null;
        }

        // `valid` now means what a verifier expects it to mean: issued, untampered, AND still
        // within its validity window. A lapsed credential verifies as genuine but not current, which
        // is why the status is reported separately rather than collapsed into a boolean.
        $companyBranded = $certificate->isCompanyBranded();

        return [
            'valid' => $certificate->isValid() && $this->signatures->verify($certificate),
            'status' => $certificate->effectiveStatus()->value,
            'number' => $certificate->number,
            'holder_name' => $certificate->user?->name,
            'course_title' => $certificate->course?->title,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'expires_at' => $certificate->expires_at?->toIso8601String(),
            'revoked_at' => $certificate->revoked_at?->toIso8601String(),
            // Shown only when the branding the certificate was issued under actually names the
            // company. A verifier learns nothing about who paid otherwise.
            'company_name' => $companyBranded ? $certificate->company_name : null,
            'company_logo_url' => $companyBranded ? $certificate->company_logo_url : null,
        ];
    }
}
