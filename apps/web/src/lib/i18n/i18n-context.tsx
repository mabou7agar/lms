"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { useHydrated } from "@/hooks/use-hydrated";
import { defaultLocale, isLocale, isRtl, localeCookieName, type Locale } from "./config";
import { dictionaries } from "./dictionaries";

const LOCALE_COOKIE_MAX_AGE = 60 * 60 * 24 * 365; // 1 year

/** Read the persisted locale from document.cookie (client only). */
function readLocaleCookie(): Locale | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${localeCookieName}=([^;]+)`));
  const value = match ? decodeURIComponent(match[1]) : null;
  return isLocale(value) ? value : null;
}

type Translate = (key: string) => string;

type I18nContextValue = {
  locale: Locale;
  dir: "ltr" | "rtl";
  t: Translate;
  setLocale: (locale: Locale) => void;
};

const I18nContext = createContext<I18nContextValue | null>(null);

/** Resolve a dot-path ("common.loading") against a dictionary; fall back to the key. */
function resolve(dict: Record<string, unknown>, key: string): string {
  const value = key.split(".").reduce<unknown>((acc, part) => {
    if (acc && typeof acc === "object" && part in (acc as Record<string, unknown>)) {
      return (acc as Record<string, unknown>)[part];
    }
    return undefined;
  }, dict);
  return typeof value === "string" ? value : key;
}

export function I18nProvider({ children, initialLocale = defaultLocale }: { children: ReactNode; initialLocale?: Locale }) {
  const [locale, setLocaleState] = useState<Locale>(initialLocale);

  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next);
    // Persist so the server layout can render the right lang/dir on the next request. The <html>
    // lang/dir attributes are reflected by the effect below whenever `locale` changes.
    if (typeof document !== "undefined") {
      document.cookie = `${localeCookieName}=${next}; path=/; max-age=${LOCALE_COOKIE_MAX_AGE}; samesite=lax`;
    }
  }, []);

  // Reconcile once from the persisted cookie after hydration (covers statically rendered pages) —
  // during render, tracked in state, so there is no setState-in-effect and no SSR mismatch.
  const hydrated = useHydrated();
  const [reconciled, setReconciled] = useState(false);
  if (hydrated && !reconciled) {
    setReconciled(true);
    const persisted = readLocaleCookie();
    if (persisted && persisted !== locale) setLocaleState(persisted);
  }

  // Reflect the active locale onto <html lang/dir> — a genuine external-system sync, so an effect is
  // the correct tool here (it sets no React state).
  useEffect(() => {
    if (typeof document === "undefined") return;
    document.documentElement.lang = locale;
    document.documentElement.dir = isRtl(locale) ? "rtl" : "ltr";
  }, [locale]);

  const value = useMemo<I18nContextValue>(() => {
    const dict = dictionaries[locale] as unknown as Record<string, unknown>;
    return {
      locale,
      dir: isRtl(locale) ? "rtl" : "ltr",
      t: (key: string) => resolve(dict, key),
      setLocale,
    };
  }, [locale, setLocale]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nContextValue {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error("useI18n must be used within I18nProvider");
  return ctx;
}
