import { cache } from "react";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getCourse } from "@/lib/catalog/api";
import { getSeo } from "@/lib/seo/api";
import { resolveLocale } from "@/lib/seo/locale";
import { buildMetadata } from "@/lib/seo/metadata";
import { CourseDetailsClient } from "./course-details-client";

/**
 * Does this course exist and is it public? Cached per render, so the existence check and the
 * metadata share one request. A failure of any kind reads as "not found" — this decides a 404, and
 * an API hiccup must not turn a real course into one... which is why the page only 404s on a
 * genuine miss (see below), never on a transport error.
 */
const loadCourse = cache(async (publicId: string) => {
  try {
    return { found: true as const, course: await getCourse(publicId) };
  } catch (error) {
    // Only a 404 from the API means the course is not there. Anything else — the API down, a
    // timeout — must not be laundered into a permanent-looking 404 that a crawler would believe.
    const status = (error as { status?: number } | null)?.status;
    return { found: status !== 404, course: null };
  }
});

type Params = { params: Promise<{ public_id: string }> };

/**
 * Static template metadata is the FALLBACK; a managed SEO override for this course (keyed by its
 * public_id) wins via the shared buildMetadata() helper. Course-specific details still load
 * client-side, so no course API dependency is introduced at build time beyond the optional override.
 */
export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { public_id } = await params;

  const fallback: Metadata = {
    title: "Course details",
    description: "View course details, curriculum, trainers and enrollment options on HElbaron.",
  };

  const [seo, locale] = await Promise.all([getSeo("course", public_id), resolveLocale()]);
  return buildMetadata(seo, fallback, locale);
}

/**
 * The page body still loads client-side; this only settles the HTTP STATUS. A missing course used
 * to answer 200 with a client-rendered "not found", which search engines index as a real page.
 */
export default async function CourseDetailsPage({ params }: Params) {
  const { public_id } = await params;
  const { found } = await loadCourse(public_id);
  if (!found) notFound();

  return <CourseDetailsClient />;
}
