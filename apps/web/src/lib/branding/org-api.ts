import { api, apiFetch } from "@/lib/api/client";
import type { ApiSuccess } from "@/types/api";
import type { Branding } from "./api";

/**
 * ORG-ADMIN white-label data layer. App-side (authenticated, tenant-scoped, policy-guarded on the
 * backend — an org only ever touches its OWN brand). Paths resolve to `/api/v1/org/*` via the
 * same-origin BFF proxy at `/api/backend/<path>`, so the client path omits the `v1` segment
 * (`org/branding`, NOT `v1/org/branding`). Mirrors `@/lib/sso/api` + `@/lib/enterprise/manager-api`.
 *
 * The read endpoint returns the effective (global-merged) {@link Branding} payload; the write accepts
 * only the flat, strictly-shaped override fields below (matching UpdateOrganizationBrandRequest).
 */

/** The flat override fields the backend PUT accepts. Every field is optional (a partial update). */
export type OrgBrandInput = {
  brand_name_en?: string | null;
  brand_name_ar?: string | null;
  logo?: string | null;
  favicon?: string | null;
  primary_color?: string | null;
  secondary_color?: string | null;
};

/** CustomDomainResource — the public-safe view of an org's white-label domain. */
export type CustomDomain = {
  id: string;
  host: string;
  is_primary: boolean;
  verified: boolean;
  verified_at: string | null;
  verification_token: string | null;
  created_at: string | null;
};

/**
 * Strict hex validator mirroring the backend rule `#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})` so an invalid
 * value is caught client-side (before the request) exactly as the server would reject it.
 */
const HEX_RE = /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;

export function isValidHex(value: string): boolean {
  return HEX_RE.test(value.trim());
}

// ── Brand override (self-scoped) ────────────────────────────────────────────────────────────────

/** GET org/branding — the caller org's effective (global-merged) brand payload. */
export const getOrgBranding = () => api.data<Branding>("org/branding");

/** PUT org/branding — upsert the caller org's brand override (only supplied keys are touched). */
export const updateOrgBranding = (input: OrgBrandInput) =>
  apiFetch<ApiSuccess<Branding>>("org/branding", { method: "PUT", body: input });

// ── Custom domains (tenant-scoped CRUD; verify is super_admin-only) ───────────────────────────────

/** GET org/domains — the caller org's custom domains. */
export const getOrgDomains = () => api.data<CustomDomain[]>("org/domains");

/** POST org/domains — claim a domain for the caller org. */
export const createOrgDomain = (host: string, isPrimary = false) =>
  api.post<ApiSuccess<CustomDomain>>("org/domains", { host, is_primary: isPrimary });

/** DELETE org/domains/{id} — remove an own-org domain. */
export const deleteOrgDomain = (id: string) => api.del<ApiSuccess<null>>(`org/domains/${id}`);

/** POST org/domains/{id}/verify — super_admin-only verification toggle (denied for everyone else). */
export const verifyOrgDomain = (id: string, verified = true) =>
  api.post<ApiSuccess<CustomDomain>>(`org/domains/${id}/verify`, { verified });
