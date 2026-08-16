<?php

namespace App\Platform\Shared\Commerce\Data;

/**
 * What a buyer needs to know about acquiring a course, flattened for API output.
 *
 * Lives in Shared because Catalog renders it on the course endpoints while Commerce is the only
 * thing that can build it — the DTO is what crosses the boundary, never a Product model. Every
 * identifier here is a public id.
 */
final class PurchaseSummary
{
    /**
     * @param  list<string>  $includedInBundleIds  public ids of bundles that also grant the course
     */
    public function __construct(
        public readonly bool $purchasable,
        public readonly ?string $productId = null,
        public readonly ?string $productType = null,
        public readonly ?string $currency = null,
        public readonly ?int $amountMinor = null,
        public readonly ?int $effectiveMinor = null,
        public readonly bool $onSale = false,
        public readonly ?string $audience = null,
        public readonly ?string $accessDurationType = null,
        public readonly ?int $accessDurationValue = null,
        public readonly ?string $accessEndsAt = null,
        public readonly bool $certificateEnabled = false,
        public readonly ?string $certificateExpiryType = null,
        public readonly ?int $certificateExpiryValue = null,
        public readonly array $includedInBundleIds = [],
    ) {}

    /** A course nothing currently sells. */
    public static function notPurchasable(): self
    {
        return new self(purchasable: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (! $this->purchasable) {
            return ['purchasable' => false];
        }

        return [
            'purchasable' => true,
            'product_id' => $this->productId,
            'product_type' => $this->productType,
            'price' => [
                'currency' => $this->currency,
                'amount_minor' => $this->amountMinor,
                'effective_minor' => $this->effectiveMinor,
                'on_sale' => $this->onSale,
            ],
            'audience' => $this->audience,
            'access' => [
                'duration_type' => $this->accessDurationType,
                'duration_value' => $this->accessDurationValue,
                'ends_at' => $this->accessEndsAt,
            ],
            'certificate' => [
                'enabled' => $this->certificateEnabled,
                'expiry_type' => $this->certificateExpiryType,
                'expiry_value' => $this->certificateExpiryValue,
            ],
            'included_in_bundles' => $this->includedInBundleIds,
        ];
    }
}
