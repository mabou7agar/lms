"use client";

import type { ReactNode } from "react";
import { RequireAuth } from "@/lib/auth/guards";

/**
 * Invitation acceptance shell. Token-authorized rather than manager-gated: any AUTHENTICATED user
 * (the bearer of the invite link) may accept or decline, so this requires a session but NO role —
 * distinct from the manager-portal layout. Minimal centered surface, no AppShell.
 */
export default function InvitationLayout({ children }: { children: ReactNode }) {
  return (
    <RequireAuth>
      <main className="mx-auto flex min-h-[70vh] w-full max-w-lg items-center justify-center px-4 py-10">
        {children}
      </main>
    </RequireAuth>
  );
}
