import { api, apiFetch } from "@/lib/api/client";
import type { ApiSuccess } from "@/types/api";

/**
 * SSO OPERATIONS data layer. App-side (authenticated). Paths resolve to the backend `/api/v1/*`
 * routes via the same-origin BFF proxy at `/api/backend/<path>`, so the client path omits the `v1`
 * segment (e.g. `sso/domains`, NOT `v1/sso/domains`).
 *
 * Covers: the caller's own linked social accounts, the honest SSO capability map, and the org-admin
 * email-domain mappings. NO provider tokens/secrets are ever returned by the backend.
 */

export type SsoDomainMode = "auto_join" | "restrict";

/** One of the caller's linked providers (never tokens/secrets — email + timestamp only). */
export type LinkedAccount = {
  id: string;
  provider: string;
  email: string | null;
  linked_at: string | null;
};

/** Data-driven capability map — the single source of truth for the "SAML unsupported" notice. */
export type SsoCapabilities = {
  sso_enabled: boolean;
  oidc: { supported: boolean; label: string; providers: string[] };
  saml: { supported: boolean; label: string; reason: string };
};

/** An organization's claimed email domain. */
export type SsoDomainMapping = {
  id: string;
  domain: string;
  mode: SsoDomainMode;
  verified: boolean;
  verified_at: string | null;
  created_at: string | null;
};

/** Linked-accounts payload. `has_password` drives the "disable the last sign-in method" UX. */
export type LinkedAccountsData = {
  accounts: LinkedAccount[];
  has_password: boolean;
};

// ── Linked accounts (own) ──────────────────────────────────────────────────────────────────────

/** GET account/linked-accounts — the caller's linked providers + whether a password exists. */
export const getLinkedAccounts = () => api.data<LinkedAccountsData>("account/linked-accounts");

/** DELETE account/linked-accounts/{id} — unlink a provider (refused when it's the last method). */
export const unlinkAccount = (id: string) =>
  api.del<ApiSuccess<null>>(`account/linked-accounts/${id}`);

// ── Capabilities ───────────────────────────────────────────────────────────────────────────────

/** GET sso/capabilities — OIDC supported, SAML unsupported (with the honest reason). */
export const getSsoCapabilities = () => api.data<SsoCapabilities>("sso/capabilities");

// ── Domain mappings (org-admin, tenant-scoped) ───────────────────────────────────────────────────

/** GET sso/domains — the caller org's domain mappings. */
export const getSsoDomains = () => api.data<SsoDomainMapping[]>("sso/domains");

/** POST sso/domains — claim a domain in a mode. */
export const createSsoDomain = (domain: string, mode: SsoDomainMode) =>
  api.post<ApiSuccess<SsoDomainMapping>>("sso/domains", { domain, mode });

/** PATCH sso/domains/{id} — change a domain's mode. */
export const updateSsoDomainMode = (id: string, mode: SsoDomainMode) =>
  apiFetch<ApiSuccess<SsoDomainMapping>>(`sso/domains/${id}`, { method: "PATCH", body: { mode } });

/** DELETE sso/domains/{id} — remove a domain mapping. */
export const deleteSsoDomain = (id: string) => api.del<ApiSuccess<null>>(`sso/domains/${id}`);
