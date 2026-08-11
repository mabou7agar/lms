"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  createOrgDomain,
  deleteOrgDomain,
  getOrgBranding,
  getOrgDomains,
  updateOrgBranding,
  verifyOrgDomain,
  type OrgBrandInput,
} from "./org-api";

/**
 * React-Query hooks for the org-admin white-label editor. Thin `useQuery` wrappers with stable keys
 * and `useMutation`s that invalidate the affected cache on success. Mirrors `@/lib/sso/hooks`.
 */

const KEYS = {
  branding: ["org", "branding"] as const,
  domains: ["org", "domains"] as const,
};

// ── Brand override ───────────────────────────────────────────────────────────────

export const useOrgBranding = () => useQuery({ queryKey: KEYS.branding, queryFn: getOrgBranding });

export function useUpdateOrgBranding() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: OrgBrandInput) => updateOrgBranding(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEYS.branding }),
  });
}

// ── Custom domains ───────────────────────────────────────────────────────────────

export const useOrgDomains = () => useQuery({ queryKey: KEYS.domains, queryFn: getOrgDomains });

function useDomainInvalidation() {
  const qc = useQueryClient();
  return () => qc.invalidateQueries({ queryKey: KEYS.domains });
}

export function useCreateOrgDomain() {
  const invalidate = useDomainInvalidation();
  return useMutation({
    mutationFn: ({ host, isPrimary }: { host: string; isPrimary?: boolean }) => createOrgDomain(host, isPrimary),
    onSuccess: invalidate,
  });
}

export function useDeleteOrgDomain() {
  const invalidate = useDomainInvalidation();
  return useMutation({ mutationFn: (id: string) => deleteOrgDomain(id), onSuccess: invalidate });
}

export function useVerifyOrgDomain() {
  const invalidate = useDomainInvalidation();
  return useMutation({
    mutationFn: ({ id, verified }: { id: string; verified?: boolean }) => verifyOrgDomain(id, verified),
    onSuccess: invalidate,
  });
}
