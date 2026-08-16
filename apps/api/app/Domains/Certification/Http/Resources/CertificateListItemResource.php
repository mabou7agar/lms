<?php

namespace App\Domains\Certification\Http\Resources;

use App\Domains\Certification\Models\Certificate;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property Certificate $resource
 */
class CertificateListItemResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'number' => $this->resource->number,
            // The EFFECTIVE status: a credential whose window has closed reads as expired even
            // though nothing wrote that state to the row.
            'status' => $this->resource->effectiveStatus()->value,
            'course_title' => $this->resource->course?->title,
            'issued_at' => $this->resource->issued_at?->toIso8601String(),
            'expires_at' => $this->resource->expires_at?->toIso8601String(),
            'expired' => $this->resource->hasExpired(),
            // Company context is exposed only when the branding policy actually puts the company on
            // the certificate — otherwise which organization paid is nobody's business.
            'company_name' => $this->resource->isCompanyBranded() ? $this->resource->company_name : null,
            'company_branded' => $this->resource->isCompanyBranded(),
        ];
    }
}
