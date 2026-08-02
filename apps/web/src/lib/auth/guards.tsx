"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, type ReactNode } from "react";
import { ShieldAlert } from "lucide-react";
import { useAuth } from "./auth-context";
import { useI18n } from "@/lib/i18n/i18n-context";
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

  const authorized = status === "authenticated" && (!roles || roles.some((r) => user?.roles?.includes(r)));

  useEffect(() => {
    if (status !== "guest") return;
    // Preserve the destination so the login page can send the user back after sign-in.
    const search = searchParams.toString();
    const current = `${pathname ?? "/"}${search ? `?${search}` : ""}`;
    router.replace(`${redirectTo}?redirect=${encodeURIComponent(current)}`);
  }, [status, router, redirectTo, pathname, searchParams]);

  if (status === "loading") return <PageLoading />;
  if (status === "guest") return null;
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

/** Requires a guest session; redirects authenticated users away (e.g. from /login). */
export function RequireGuest({ children, redirectTo = "/dashboard" }: { children: ReactNode; redirectTo?: string }) {
  const { status } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (status === "authenticated") router.replace(redirectTo);
  }, [status, router, redirectTo]);

  if (status === "loading") return <PageLoading />;
  if (status === "authenticated") return null;

  return <>{children}</>;
}
