import type { Metadata } from "next";
import { InstructorApplyPage } from "@/components/marketing/instructor-apply-page";

const TITLE = "Become an instructor — HElbaron";
const DESCRIPTION =
  "Apply to teach on HElbaron. Share your expertise, build Arabic-first courses, and reach learners across the region. Tell us about yourself and what you'd like to teach.";

export const metadata: Metadata = {
  title: TITLE,
  description: DESCRIPTION,
  alternates: { canonical: "/teach/apply" },
  openGraph: { title: TITLE, description: DESCRIPTION, url: "/teach/apply", type: "website" },
  twitter: { card: "summary_large_image", title: TITLE, description: DESCRIPTION },
};

export default function Page() {
  return <InstructorApplyPage />;
}
