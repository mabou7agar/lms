import type { ReactNode } from "react";
import { LearnerFrameHeader } from "@/components/learning/learner-frame-header";

/**
 * Player surface frame. The marketing chrome (AnnouncementBar / LandingHeader /
 * LandingFooter) is intentionally gone here — the learner player gets a slim,
 * focused frame instead. Auth is enforced per-page (RequireAuth), not in the layout.
 */
export default function PlayerLayout({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-dvh flex-col">
      <LearnerFrameHeader />
      <main id="main-content" className="flex-1">{children}</main>
    </div>
  );
}
