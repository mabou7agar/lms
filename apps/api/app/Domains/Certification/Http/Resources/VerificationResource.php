<?php

namespace App\Domains\Certification\Http\Resources;

use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Public verification result — issuance facts only. Wrap an array from
 * CertificateVerificationService.
 */
class VerificationResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'valid' => $this->resource['valid'],
            'status' => $this->resource['status'],
            'number' => $this->resource['number'],
            'holder_name' => $this->resource['holder_name'],
            'course_title' => $this->resource['course_title'],
            'issued_at' => $this->resource['issued_at'],
            'expires_at' => $this->resource['expires_at'],
            'revoked_at' => $this->resource['revoked_at'],
            'company_name' => $this->resource['company_name'],
            'company_logo_url' => $this->resource['company_logo_url'],
        ];
    }
}
