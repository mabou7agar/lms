"use client";

import type { ReactNode } from "react";
import { usePathname } from "next/navigation";
import { RequireAuth } from "@/lib/auth/guards";
import { AppShell } from "@/components/layout/app-shell";
import { AnnouncementBar } from "@/components/landing/announcement-bar";
import { LandingHeader } from "@/components/landing/landing-header";
import { LandingFooter } from "@/components/landing/landing-footer";
import { PageTransition } from "@/components/layout/page-transition";
import { instructorNav } from "@/config/nav";

/**
 * Instructor portal. Every teaching surface requires an instructor/admin role — except the public
 * "become an instructor" application at /teach/apply. That page is the top of the recruitment funnel
 * and must be reachable by anyone (guests included), so it is rendered as a public marketing page
 * with the landing header/footer chrome instead of being wrapped in RequireAuth + the account shell.
 * This mirrors how (commerce)/layout.tsx special-cases /admin to a different treatment. Keeping the
 * branch keyed on the exact pathname ensures the authenticated /teach dashboard and all other
 * /teach/* routes stay guarded exactly as before.
 */
export default function InstructorLayout({ children }: { children: ReactNode }) {
  const pathname = usePathname();

  if (pathname === "/teach/apply") {
    return (
      <div className="flex min-h-dvh flex-col">
        <AnnouncementBar />
        <LandingHeader />
        <main id="main-content" className="flex-1">
          <PageTransition className="mx-auto w-full max-w-6xl px-4 py-10">{children}</PageTransition>
        </main>
        <LandingFooter />
      </div>
    );
  }

  return (
    <RequireAuth roles={["instructor", "admin", "super_admin"]}>
      <AppShell nav={instructorNav} location="instructor-sidebar">{children}</AppShell>
    </RequireAuth>
  );
}
