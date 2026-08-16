<?php

namespace App\Platform\Shared\Enterprise\Data;

/**
 * The company profile captured at registration. Scalars only, so it can cross from Identity to CRM
 * without either context learning the other's models.
 */
final class CompanyRegistrationInput
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $size = null,
        public readonly ?string $country = null,
        public readonly ?string $industry = null,
        public readonly ?string $phone = null,
        public readonly ?string $taxId = null,
        public readonly ?string $billingAddress = null,
        public readonly ?string $website = null,
        public readonly ?string $locale = null,
    ) {}
}
