import type { Metadata } from "next";
import { BundlesPageClient } from "./bundles-page-client";

const TITLE = "Course bundles — HElbaron";
const DESCRIPTION =
  "Buy several HElbaron courses in one purchase — for yourself or for your team, with seats your organization can assign.";

export const metadata: Metadata = {
  title: TITLE,
  description: DESCRIPTION,
  alternates: { canonical: "/bundles" },
  openGraph: { title: TITLE, description: DESCRIPTION, url: "/bundles", type: "website" },
  twitter: { card: "summary_large_image", title: TITLE, description: DESCRIPTION },
};

export default function BundlesPage() {
  return <BundlesPageClient />;
}
