"use client";

import type { ReactNode } from "react";
import { AppShell } from "@/components/layout/app-shell";
import { AdminGuard } from "@/components/commerce/admin-guard";
import { adminNav } from "@/config/nav";

/**
 * Commerce admin console shell. The parent (commerce) layout defers to this layout for /admin/*
 * routes, so the admin pages (analytics, orders, coupons, credit notes) render inside their own
 * AppShell with the admin sidebar instead of the commerce one. AdminGuard (which already requires
 * an authenticated session) keeps the whole console gated to admin roles.
 */
export default function AdminLayout({ children }: { children: ReactNode }) {
  return (
    <AdminGuard>
      <AppShell nav={adminNav}>{children}</AppShell>
    </AdminGuard>
  );
}
