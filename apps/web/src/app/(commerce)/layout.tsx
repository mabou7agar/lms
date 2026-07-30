"use client";

import type { ReactNode } from "react";
import { RequireAuth } from "@/lib/auth/guards";
import { AppShell } from "@/components/layout/app-shell";
import { commerceNav } from "@/config/nav";

/**
 * Authenticated commerce workspace (orders, billing, subscriptions, contracts). Uses the shared
 * AppShell so the commerce nav is always present — desktop sidebar and mobile drawer — rather than
 * the marketing header, which left billing/subscriptions reachable only by typing the URL.
 */
export default function CommerceLayout({ children }: { children: ReactNode }) {
  return (
    <RequireAuth>
      <AppShell nav={commerceNav}>{children}</AppShell>
    </RequireAuth>
  );
}
