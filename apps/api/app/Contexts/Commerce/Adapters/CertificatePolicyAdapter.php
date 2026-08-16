<?php

namespace App\Contexts\Commerce\Adapters;

use App\Contexts\Commerce\Services\CertificatePolicyResolver;
use App\Platform\Shared\Commerce\Contracts\CertificatePolicyPort;
use App\Platform\Shared\Commerce\Data\CertificateCommercialPolicy;
use Illuminate\Support\Carbon;

/**
 * Commerce's implementation of the Shared CertificatePolicyPort. Thin by design: it parses the issue
 * instant and hands off to CertificatePolicyResolver, which owns the actual precedence between a
 * company seat's snapshot and a personal purchase's live product policy.
 */
class CertificatePolicyAdapter implements CertificatePolicyPort
{
    public function __construct(private readonly CertificatePolicyResolver $resolver) {}

    public function certificatePolicyFor(int $userId, int $courseId, string $issuedAt): CertificateCommercialPolicy
    {
        return $this->resolver->resolve($userId, $courseId, Carbon::parse($issuedAt));
    }
}
