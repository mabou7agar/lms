"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { api, hasSession, sessionLogin, sessionLogout, type ApiRequestError } from "@/lib/api/client";
import { useHydrated } from "@/hooks/use-hydrated";
import type { AuthUser } from "@/types/api";

type AuthState = {
  user: AuthUser | null;
  status: "loading" | "authenticated" | "guest";
  login: (email: string, password: string, mfaCode?: string) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
};

const AuthContext = createContext<AuthState | null>(null);

const CACHE_KEY = "helbaron.user";

/**
 * Optimistic profile cache (non-credential data) so reloads don't flash a full-page loader
 * while /profile revalidates. The auth token itself lives in an httpOnly cookie and is never
 * readable from JS.
 */
function readCachedUser(): AuthUser | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = window.localStorage.getItem(CACHE_KEY);
    return raw ? (JSON.parse(raw) as AuthUser) : null;
  } catch {
    return null;
  }
}
function writeCachedUser(user: AuthUser | null): void {
  if (typeof window === "undefined") return;
  try {
    if (user) window.localStorage.setItem(CACHE_KEY, JSON.stringify(user));
    else window.localStorage.removeItem(CACHE_KEY);
  } catch {
    /* ignore quota/private-mode errors */
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [status, setStatus] = useState<AuthState["status"]>("loading");

  // Network revalidation (assumes a session exists). Every state update happens AFTER the await, so
  // it is asynchronous external work — never a synchronous cascade inside an effect body.
  const revalidate = useCallback(async () => {
    try {
      const me = await api.data<AuthUser>("profile");
      setUser(me);
      writeCachedUser(me);
      setStatus("authenticated");
    } catch {
      // Invalid/expired session: clear local state AND the non-httpOnly session marker cookie.
      // Without clearing the marker, hasSession() keeps returning true after the token expires,
      // so guest-guards (e.g. on /login) treat the user as authenticated and redirect away — the
      // user gets trapped and can never reach the sign-in form to re-authenticate.
      if (typeof document !== "undefined") {
        document.cookie = "helbaron_authed=; path=/; max-age=0; SameSite=Lax";
      }
      writeCachedUser(null);
      setUser(null);
      setStatus("guest");
    }
  }, []);

  const refresh = useCallback(async () => {
    if (!hasSession()) {
      writeCachedUser(null);
      setStatus("guest");
      setUser(null);
      return;
    }
    await revalidate();
  }, [revalidate]);

  // Optimistically hydrate from the client-only cache once, after hydration (adjust-state-while-
  // rendering, tracked in state — never a ref, which cannot be touched during render). Avoids the
  // full-page loading flash without a setState-in-effect, and never runs during SSR.
  const hydrated = useHydrated();
  const [primedFromCache, setPrimedFromCache] = useState(false);
  if (hydrated && !primedFromCache) {
    setPrimedFromCache(true);
    if (hasSession()) {
      const cached = readCachedUser();
      if (cached) {
        setUser(cached);
        setStatus("authenticated");
      }
    } else {
      // No session: resolve straight to guest (was previously done by refresh's sync branch).
      setStatus("guest");
    }
  }

  useEffect(() => {
    // Revalidate against the API on mount when a session exists. Inlined as an async IIFE so every
    // state update provably happens AFTER the await — asynchronous external work, never a synchronous
    // cascade in the effect body. `refresh()`/`revalidate()` remain for the consumer-triggered paths.
    if (!hasSession()) return;
    let cancelled = false;
    void (async () => {
      try {
        const me = await api.data<AuthUser>("profile");
        if (cancelled) return;
        setUser(me);
        writeCachedUser(me);
        setStatus("authenticated");
      } catch {
        if (cancelled) return;
        if (typeof document !== "undefined") {
          document.cookie = "helbaron_authed=; path=/; max-age=0; SameSite=Lax";
        }
        writeCachedUser(null);
        setUser(null);
        setStatus("guest");
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const login = useCallback(async (email: string, password: string, mfaCode?: string) => {
    const { user: me } = await sessionLogin({
      email,
      password,
      mfa_code: mfaCode,
      device_name: "web",
    });
    setUser(me);
    writeCachedUser(me);
    setStatus("authenticated");
  }, []);

  const logout = useCallback(async () => {
    try {
      await sessionLogout();
    } finally {
      writeCachedUser(null);
      setUser(null);
      setStatus("guest");
    }
  }, []);

  const value = useMemo<AuthState>(() => ({ user, status, login, logout, refresh }), [user, status, login, logout, refresh]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}

export type { ApiRequestError };
