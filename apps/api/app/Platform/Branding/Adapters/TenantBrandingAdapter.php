<?php

declare(strict_types=1);

namespace App\Platform\Branding\Adapters;

use App\Platform\Branding\Services\OrganizationBrandingService;
use App\Platform\Shared\Tenancy\Contracts\TenantBrandingProvider;
use App\Platform\Shared\Tenancy\Lifecycle\TenantBranding;
use App\Platform\Shared\Tenancy\TenantId;

/**
 * The real implementation of the Shared TenantBrandingProvider, which had been declared with a note
 * that it would be implemented "later" and then consumed by nothing.
 *
 * It is needed now because a company-branded certificate has to show the company's mark, and
 * Commerce may not read the branding tables directly. The org's effective brand is already computed
 * by OrganizationBrandingService (its override deep-merged over the global brand), so this adapter
 * only projects that payload down to the four display attributes the Shared DTO carries.
 *
 * Empty strings in the stored payload are normalised to null: the brand groups are seeded with empty
 * placeholders, and a consumer asking "is there a logo?" must not be handed "".
 */
final class TenantBrandingAdapter implements TenantBrandingProvider
{
    public function __construct(private readonly OrganizationBrandingService $branding) {}

    public function brandingFor(TenantId $id): TenantBranding
    {
        $payload = $this->branding->payloadForOrganization((int) $id->value);

        $identity = is_array($payload['identity'] ?? null) ? $payload['identity'] : [];
        $logos = is_array($payload['logos'] ?? null) ? $payload['logos'] : [];
        $theme = is_array($payload['theme'] ?? null) ? $payload['theme'] : [];
        $colors = is_array($theme['colors'] ?? null) ? $theme['colors'] : [];

        return new TenantBranding(
            displayName: $this->text($this->localized($identity['brand_name'] ?? null)),
            logoUrl: $this->text($logos['logo_light'] ?? null),
            primaryColor: $this->text($colors['primary'] ?? null),
            theme: $this->text($theme['mode'] ?? null),
        );
    }

    /** Brand name is stored as a locale map; the English entry is the neutral display fallback. */
    private function localized(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return $value['en'] ?? reset($value) ?: null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
