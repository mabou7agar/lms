<?php

namespace App\Platform\Branding\Services;

use App\Platform\Branding\Models\BrandSetting;
use App\Platform\Branding\Models\CustomDomain;
use App\Platform\Branding\Models\OrganizationBrandSetting;
use App\Platform\Shared\Html\HtmlSanitizer;

/**
 * The brand resolution + org-override write logic for the white-label module.
 *
 *  - resolveByHost(): given an incoming Host header, returns the ACTIVE public brand payload — a
 *    VERIFIED custom domain maps to its org's merged brand; an unknown/unverified host falls back to
 *    the GLOBAL brand, byte-for-byte as today. Shape is always the global 5-group payload; only the
 *    VALUES change.
 *  - payloadForOrganization(): the effective brand for an org (global merged with its override, or the
 *    plain global brand when the org has no override) — used by the org-admin read endpoint.
 *  - applyOverrides(): maps a validated, sanitised org-admin request onto the org's override row,
 *    merging OVER whatever the org previously set so a partial update never wipes untouched fields.
 *
 * Depends only on Shared (HtmlSanitizer) + this module's own models. It never imports a CRM/other
 * context model: the org is always supplied as an opaque id resolved from the tenant/host upstream.
 */
class OrganizationBrandingService
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /**
     * Resolve the active public brand for an incoming Host. Falls back to the global brand for an
     * empty/unknown host or a host with no VERIFIED custom domain (an unverified domain never resolves).
     *
     * @return array<string, array<string, mixed>>
     */
    public function resolveByHost(?string $host): array
    {
        $global = $this->globalPayload();

        $normalized = $this->normalizeHost($host);

        if ($normalized === '') {
            return $global;
        }

        $domain = CustomDomain::query()->verifiedHost($normalized)->first();

        if ($domain === null) {
            return $global;
        }

        return $this->mergeOrganization($domain->organization_id, $global);
    }

    /**
     * The effective public brand for one organization: its override merged over the global brand, or
     * the plain global brand when the org has set no override.
     *
     * @return array<string, array<string, mixed>>
     */
    public function payloadForOrganization(int $organizationId): array
    {
        return $this->mergeOrganization($organizationId, $this->globalPayload());
    }

    /**
     * Upsert an org's brand override from a VALIDATED, sanitised org-admin request. Only the supplied
     * keys are touched; each is deep-merged over the org's existing stored groups.
     *
     * @param  array<string, mixed>  $validated
     */
    public function applyOverrides(int $organizationId, array $validated): OrganizationBrandSetting
    {
        $brand = OrganizationBrandSetting::firstOrCreate(['organization_id' => $organizationId]);

        $identity = $brand->identity ?? [];
        $logos = $brand->logos ?? [];
        $theme = $brand->theme ?? [];

        if (array_key_exists('brand_name_en', $validated) && $validated['brand_name_en'] !== null) {
            $identity['brand_name']['en'] = $this->plainText((string) $validated['brand_name_en']);
        }
        if (array_key_exists('brand_name_ar', $validated) && $validated['brand_name_ar'] !== null) {
            $identity['brand_name']['ar'] = $this->plainText((string) $validated['brand_name_ar']);
        }
        if (array_key_exists('logo', $validated) && $validated['logo'] !== null) {
            $logos['logo_light'] = (string) $validated['logo'];
        }
        if (array_key_exists('favicon', $validated) && $validated['favicon'] !== null) {
            $logos['favicon'] = (string) $validated['favicon'];
        }
        if (array_key_exists('primary_color', $validated) && $validated['primary_color'] !== null) {
            $theme['colors']['primary'] = (string) $validated['primary_color'];
        }
        if (array_key_exists('secondary_color', $validated) && $validated['secondary_color'] !== null) {
            $theme['colors']['secondary'] = (string) $validated['secondary_color'];
        }

        $brand->update([
            'identity' => $identity === [] ? null : $identity,
            'logos' => $logos === [] ? null : $logos,
            'theme' => $theme === [] ? null : $theme,
        ]);

        return $brand->refresh();
    }

    /**
     * @param  array<string, array<string, mixed>>  $global
     * @return array<string, array<string, mixed>>
     */
    private function mergeOrganization(int $organizationId, array $global): array
    {
        $override = OrganizationBrandSetting::query()->forOrganization($organizationId)->first();

        return $override === null ? $global : $override->mergedOver($global);
    }

    /**
     * The GLOBAL brand payload — the single BrandSetting row's defaults-merged public array, exactly
     * as the public endpoint has always returned it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function globalPayload(): array
    {
        return BrandSetting::current()->toPublicArray();
    }

    /** Lowercase host with scheme, path and port stripped (defence-in-depth; the FormRequest also normalises). */
    private function normalizeHost(?string $host): string
    {
        if ($host === null) {
            return '';
        }

        $host = strtolower(trim($host));
        $host = preg_replace('#^[a-z]+://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];

        return explode(':', $host)[0];
    }

    /** Strip all markup from free-text brand fields (defence-in-depth over the request validation). */
    private function plainText(string $value): string
    {
        return trim(strip_tags($this->sanitizer->sanitize($value)));
    }
}
