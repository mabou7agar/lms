<?php

namespace App\Platform\Shared\Enterprise\Data;

/**
 * An organization's identity as other contexts need it: enough to address an invoice and name the
 * buyer, without exposing the CRM model.
 */
final class OrganizationRef
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $name,
        public readonly ?string $country = null,
        public readonly ?string $phone = null,
        public readonly ?string $taxId = null,
        public readonly ?string $billingAddress = null,
    ) {}
}
