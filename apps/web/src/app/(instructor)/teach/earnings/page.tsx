import type { Metadata } from "next";
import { ComingSoon } from "@/components/states/coming-soon";

/**
 * DORMANT ROUTE — deliberately not linked from any navigation.
 *
 * The backend context behind it is not built, so the page renders ComingSoon. It is kept (rather
 * than deleted) because the URL is stable and the surface is planned; nothing references it, so no
 * one reaches it by accident. Add the nav entry in src/config/nav.ts when the data exists.
 */
export const metadata: Metadata = { title: "Earnings" };

export default function Page() {
  return <ComingSoon eyebrow="Instructor" title="Earnings" icon="BarChart3" />;
}
