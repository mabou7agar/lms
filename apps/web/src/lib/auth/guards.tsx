"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, type ReactNode } from "react";
import { ShieldAlert } from "lucide-react";
import { useAuth } from "./auth-context";
import { useI18n } from "@/lib/i18n/i18n-context";
import { safeRedirect } from "@/lib/utils";
import { PageLoading } from "@/components/states/loading-state";
import { EmptyState } from "@/components/states/empty-state";
import { Button } from "@/components/ui/button";

/** Shown when the user is authenticated but lacks the required role/permission. */
function AccessDenied() {
  const { t } = useI18n();
  return (
    <EmptyState
      icon={<ShieldAlert className="size-8" aria-hidden />}
      title={t("common.accessDenied.title")}
      description={t("common.accessDenied.description")}
      action={
        <Button asChild variant="outline" size="sm">
          <Link href="/">{t("common.accessDenied.goHome")}</Link>
        </Button>
      }
    />
  );
}

function RequireAuthInner({ children, redirectTo = "/login", roles }: { children: ReactNode; redirectTo?: string; roles?: string[] }) {
  const { status, user } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  // `org_manager` is not a Spatie role — enterprise manager authority is org-membership-based
  // (backend ManagerScope). The profile exposes `is_org_manager`, so treat that virtual role as
  // satisfied by the capability flag. All other roles are matched against the real role list.
  const authorized =
    status === "authenticated" &&
    user?.email_verified !== false &&
    (!roles ||
      roles.some((r) => user?.roles?.includes(r)) ||
      (roles.includes("org_manager") && user?.is_org_manager === true));

  useEffect(() => {
    if (status !== "guest") return;
    // Preserve the destination so the login page can send the user back after sign-in.
    const search = searchParams.toString();
    const current = `${pathname ?? "/"}${search ? `?${search}` : ""}`;
    router.replace(`${redirectTo}?redirect=${encodeURIComponent(current)}`);
  }, [status, router, redirectTo, pathname, searchParams]);

  useEffect(() => {
    if (status !== "authenticated" || user?.email_verified !== false) return;
    const search = searchParams.toString();
    const current = `${pathname ?? "/"}${search ? `?${search}` : ""}`;
    router.replace(`/verify-email?redirect=${encodeURIComponent(current)}`);
  }, [status, user?.email_verified, router, pathname, searchParams]);

  if (status === "loading") return <PageLoading />;
  if (status === "guest") return null;
  if (user?.email_verified === false) return <PageLoading />;
  if (!authorized) return <AccessDenied />;

  return <>{children}</>;
}

/** Requires an authenticated session; redirects guests to the sign-in route. */
export function RequireAuth(props: { children: ReactNode; redirectTo?: string; roles?: string[] }) {
  // useSearchParams needs a Suspense boundary during prerender; keep it self-contained here.
  return (
    <Suspense fallback={<PageLoading />}>
      <RequireAuthInner {...props} />
    </Suspense>
  );
}

function RequireGuestInner({ children, redirectTo = "/dashboard" }: { children: ReactNode; redirectTo?: string }) {
  const { status } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();

  useEffect(() => {
    if (status !== "authenticated") return;
    // Honour the destination the sign-in flow was started from. This guard wraps the whole (auth)
    // layout, so it fires the moment login flips the session to authenticated — racing the sign-in
    // page's own redirect. Sending both to the SAME target is what makes `?redirect=` work at all:
    // previously the layout's unconditional replace(redirectTo) always won, so every sign-in landed
    // on the layout's fallback and the parameter was silently dead for every caller that sets it
    // (the auth guard, the middleware, and the course/event/product sign-in CTAs).
    router.replace(safeRedirect(searchParams.get("redirect"), redirectTo));
  }, [status, router, redirectTo, searchParams]);

  if (status === "loading") return <PageLoading />;
  if (status === "authenticated") return null;

  return <>{children}</>;
}

/** Requires a guest session; redirects authenticated users away (e.g. from /login). */
export function RequireGuest(props: { children: ReactNode; redirectTo?: string }) {
  // useSearchParams needs a Suspense boundary during prerender; keep it self-contained here.
  return (
    <Suspense fallback={<PageLoading />}>
      <RequireGuestInner {...props} />
    </Suspense>
  );
}
