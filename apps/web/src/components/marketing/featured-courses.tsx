"use client";

import { useSearchParams } from "next/navigation";
import { useFeaturedCourses } from "@/lib/catalog/hooks";
import { useI18n } from "@/lib/i18n/i18n-context";
import { CourseCover, courseListItemToCover } from "@/components/marketing/course-cover";
import { FeaturedShell } from "./featured-shell";
import { CourseCardsCinematic } from "./course-cards-cinematic";
import { CourseCardsPaths } from "./course-cards-paths";

/** Selectable homepage card treatment, chosen client-side via `?courseCards=`. */
type CardsVariant = "editorial" | "cinematic" | "paths";

function readVariant(value: string | null): CardsVariant {
  return value === "cinematic" || value === "paths" ? value : "editorial";
}

/**
 * Homepage featured-courses surface. Renders the REAL published courses flagged `is_featured` in one
 * of three selectable visual treatments, chosen client-side via the `?courseCards=` query param
 * (editorial | cinematic | paths; default + unknown → editorial). The section heading and CTA are
 * shared across variants via <FeaturedShell>; only ONE grid renders at a time. Renders nothing while
 * loading or when there are no courses, so the homepage is never blank-with-error.
 */
export function FeaturedCourses() {
  const variant = readVariant(useSearchParams().get("courseCards"));

  // The program-path variant is driven by the full published catalog (not just featured) and owns its
  // own data + empty handling, so it renders the shell itself.
  if (variant === "paths") return <CourseCardsPaths />;

  return <FeaturedCoursesFeatured variant={variant} />;
}

/** Editorial + cinematic share the featured-courses query; the shell wraps whichever grid renders. */
function FeaturedCoursesFeatured({ variant }: { variant: "editorial" | "cinematic" }) {
  const { locale } = useI18n();
  const query = useFeaturedCourses();
  const courses = query.data?.data ?? [];

  if (courses.length === 0) return null;

  return (
    <FeaturedShell>
      {variant === "cinematic" ? (
        <CourseCardsCinematic courses={courses} />
      ) : (
        <div className="stagger-in grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {courses.map((course, i) => (
            <CourseCover key={course.id} course={courseListItemToCover(course, locale)} wave="cradle" index={i + 1} minimal />
          ))}
        </div>
      )}
    </FeaturedShell>
  );
}
