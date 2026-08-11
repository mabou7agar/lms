"use client";

import type { ReactNode } from "react";
import { RequireAuth } from "@/lib/auth/guards";
import { AppShell } from "@/components/layout/app-shell";
import { managerNav } from "@/config/nav";

/**
 * Enterprise MANAGER PORTAL shell. Gated to org manager / admin roles — a plain member is shown the
 * access-denied state by RequireAuth. Mirrors the organization layout's guard + AppShell pattern.
 */
export default function EnterpriseLayout({ children }: { children: ReactNode }) {
  return (
    <RequireAuth roles={["org_manager", "admin", "super_admin"]}>
      <AppShell nav={managerNav}>{children}</AppShell>
    </RequireAuth>
  );
}
