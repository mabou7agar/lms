"use client";

import { useEffect, useState } from "react";
import { useAuth } from "@/lib/auth/auth-context";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useFeatureFlags } from "@/lib/flags/hooks";
import { getNavigation, resolveNav, type MenuLocation, type NavNode } from "./api";

/**
 * Client hook that loads a CMS menu location and filters it for the CURRENT visitor (locale, auth
 * state, roles, feature flags). Returns null until loaded and on any failure/empty menu — the caller
 * then renders its hardcoded fallback so navigation never disappears. Re-filters (without refetching)
 * when the locale, auth state, or flags change. Nav items carry no flag by default, so flag gating
 * only affects items an admin has explicitly bound to a flag key.
 */
export function useNavigation(location?: MenuLocation): NavNode[] | null {
  const { locale } = useI18n();
  const { status, user } = useAuth();
  const flags = useFeatureFlags();
  const [raw, setRaw] = useState<NavNode[] | null>(null);

  useEffect(() => {
    if (!location) return;
    let active = true;
    void getNavigation(location).then((res) => {
      if (active) setRaw(res);
    });
    return () => {
      active = false;
    };
  }, [location]);

  // No location (or not loaded yet) → return null so the caller renders its hardcoded fallback.
  // Deriving this avoids a setState-in-effect purely to clear `raw`.
  if (!location || !raw) return null;

  return resolveNav(raw, {
    locale,
    authed: status === "authenticated",
    roles: user?.roles ?? [],
    flags,
  });
}
