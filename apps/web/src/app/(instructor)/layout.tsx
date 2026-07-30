"use client";

import type { ReactNode } from "react";
import { usePathname } from "next/navigation";
import { RequireAuth } from "@/lib/auth/guards";
import { AppShell } from "@/components/layout/app-shell";
import { accountNav, instructorNav } from "@/config/nav";

/**
 * Instructor portal. Teaching surfaces require an instructor/admin role, but the "apply to teach"
 * page must be reachable by any authenticated learner who wants to become an instructor — otherwise
 * the application funnel is walled off from exactly the people it is for. On that one route the
 * guard is auth-only AND the instructor-only sidebar is replaced with the neutral account nav, so a
 * non-instructor is never shown (or dead-ended into) teaching surfaces they cannot open.
 */
export default function InstructorLayout({ children }: { children: ReactNode }) {
  const pathname = usePathname();

  if (pathname === "/teach/apply") {
    return (
      <RequireAuth>
        <AppShell nav={accountNav}>{children}</AppShell>
      </RequireAuth>
    );
  }

  return (
    <RequireAuth roles={["instructor", "admin", "super_admin"]}>
      <AppShell nav={instructorNav} location="instructor-sidebar">{children}</AppShell>
    </RequireAuth>
  );
}
