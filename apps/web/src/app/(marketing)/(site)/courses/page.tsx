import type { Metadata } from "next";
import { getSeo } from "@/lib/seo/api";
import { resolveLocale } from "@/lib/seo/locale";
import { buildMetadata } from "@/lib/seo/metadata";
import { CoursesPageClient } from "./courses-page-client";

/** Static metadata is the fallback; a managed SEO override (marketing_page/courses) wins. */
export async function generateMetadata(): Promise<Metadata> {
  const fallback: Metadata = {
    title: "Courses",
    description: "Browse the full HElbaron course catalog — filter by category, level and language to find your next course.",
    alternates: { canonical: "/courses" },
    openGraph: {
      title: "Courses",
      description: "Browse the full HElbaron course catalog — filter by category, level and language to find your next course.",
      url: "/courses",
      type: "website",
    },
    twitter: {
      card: "summary_large_image",
      title: "Courses",
      description: "Browse the full HElbaron course catalog — filter by category, level and language to find your next course.",
    },
  };

  const [seo, locale] = await Promise.all([getSeo("marketing_page", "courses"), resolveLocale()]);
  return buildMetadata(seo, fallback, locale);
}

export default function CoursesPage() {
  return <CoursesPageClient />;
}
