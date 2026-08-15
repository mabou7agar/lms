"use client";

import type { ReactNode } from "react";
import { usePathname } from "next/navigation";
import { RequireAuth } from "@/lib/auth/guards";
import { AppShell } from "@/components/layout/app-shell";
import { commerceNav } from "@/config/nav";

/**
 * Authenticated commerce workspace (orders, billing, subscriptions, contracts). Uses the shared
 * AppShell so the commerce nav is always present — desktop sidebar and mobile drawer — rather than
 * the marketing header, which left billing/subscriptions reachable only by typing the URL.
 *
 * The admin console (/admin/*) provides its own AppShell with the admin sidebar via
 * (commerce)/admin/layout.tsx. Rendering the commerce shell here as well would double the
 * sidebar/topbar, so we defer to the nested admin layout for those routes.
 */
export default function CommerceLayout({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  if (pathname?.startsWith("/admin")) {
    return <RequireAuth>{children}</RequireAuth>;
  }
  return (
    <RequireAuth>
      <AppShell nav={commerceNav}>{children}</AppShell>
    </RequireAuth>
  );
}
