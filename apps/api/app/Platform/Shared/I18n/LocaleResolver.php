<?php

declare(strict_types=1);

namespace App\Platform\Shared\I18n;

use App\Platform\Shared\Helpers\LocaleHelper;
use App\Platform\Shared\Tenancy\Contracts\CurrentTenantProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic locale-resolution policy for a request. Precedence (first match wins), every
 * candidate normalised against the supported allowlist (config/shared.php); IP is never used:
 *   1. Authenticated user preference (users.locale)
 *   2. Explicit request locale — ?locale= then Accept-Language
 *   3. Organization default (crm_organizations.locale for the active tenant, read via the tenant
 *      context so it can never leak across tenants)
 *   4. Application fallback locale
 *
 * The tenant lookup uses the query builder (not the CRM model) so the Shared layer keeps no
 * dependency on a domain. An unsupported value normalises to null (falls through), so an
 * unsupported explicit locale is safely ignored rather than honoured.
 */
final class LocaleResolver
{
    public function __construct(private readonly CurrentTenantProvider $tenant) {}

    public function resolve(Request $request): string
    {
        foreach ([
            $this->fromUser($request),
            $this->fromRequest($request),
            $this->fromOrganization(),
        ] as $candidate) {
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return LocaleHelper::fallback();
    }

    private function fromUser(Request $request): ?string
    {
        $user = $request->user();

        // Read the locale via the Eloquent model (not the Authenticatable contract) so this stays
        // both static-analysis clean and free of a cross-domain dependency on the User model.
        return $user instanceof Model ? $this->normalize($user->getAttribute('locale')) : null;
    }

    private function fromRequest(Request $request): ?string
    {
        $explicit = $this->normalize($request->query('locale'));

        if ($explicit !== null) {
            return $explicit;
        }

        foreach ($request->getLanguages() as $language) {
            $normalized = $this->normalize($language);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function fromOrganization(): ?string
    {
        $tenant = $this->tenant->currentTenant();

        if ($tenant === null) {
            return null;
        }

        $locale = DB::table('crm_organizations')->where('id', $tenant->value)->value('locale');

        return is_string($locale) ? $this->normalize($locale) : null;
    }

    private function normalize(mixed $locale): ?string
    {
        if (! is_string($locale) || $locale === '') {
            return null;
        }

        $short = strtolower(substr($locale, 0, 2));

        return in_array($short, LocaleHelper::supported(), true) ? $short : null;
    }
}
