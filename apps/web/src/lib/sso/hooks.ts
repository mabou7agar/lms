"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  createSsoDomain,
  deleteSsoDomain,
  getLinkedAccounts,
  getSsoCapabilities,
  getSsoDomains,
  unlinkAccount,
  updateSsoDomainMode,
  type SsoDomainMode,
} from "./api";

/**
 * React-Query hooks for SSO operations. Thin `useQuery` wrappers with stable keys and `useMutation`s
 * that invalidate the affected cache on success. Mirrors `@/lib/enterprise/manager-hooks`.
 */

const KEYS = {
  linked: ["sso", "linked-accounts"] as const,
  capabilities: ["sso", "capabilities"] as const,
  domains: ["sso", "domains"] as const,
};

// ── Linked accounts ──────────────────────────────────────────────────────────────

export const useLinkedAccounts = () => useQuery({ queryKey: KEYS.linked, queryFn: getLinkedAccounts });

export function useUnlinkAccount() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => unlinkAccount(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEYS.linked }),
  });
}

// ── Capabilities ─────────────────────────────────────────────────────────────────

export const useSsoCapabilities = () =>
  useQuery({ queryKey: KEYS.capabilities, queryFn: getSsoCapabilities, staleTime: 5 * 60 * 1000 });

// ── Domain mappings ──────────────────────────────────────────────────────────────

export const useSsoDomains = () => useQuery({ queryKey: KEYS.domains, queryFn: getSsoDomains });

function useDomainInvalidation() {
  const qc = useQueryClient();
  return () => qc.invalidateQueries({ queryKey: KEYS.domains });
}

export function useCreateSsoDomain() {
  const invalidate = useDomainInvalidation();
  return useMutation({
    mutationFn: ({ domain, mode }: { domain: string; mode: SsoDomainMode }) => createSsoDomain(domain, mode),
    onSuccess: invalidate,
  });
}

export function useUpdateSsoDomainMode() {
  const invalidate = useDomainInvalidation();
  return useMutation({
    mutationFn: ({ id, mode }: { id: string; mode: SsoDomainMode }) => updateSsoDomainMode(id, mode),
    onSuccess: invalidate,
  });
}

export function useDeleteSsoDomain() {
  const invalidate = useDomainInvalidation();
  return useMutation({ mutationFn: (id: string) => deleteSsoDomain(id), onSuccess: invalidate });
}
