"use client";

import { useSearchParams } from "next/navigation";
import { useCourses } from "@/lib/catalog/hooks";
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
 * Homepage course-selling surface. It renders nine REAL published paid courses, not just the few
 * admin-flagged as featured; the homepage must prove there is a catalogue to buy from. Visual
 * treatment is selectable via `?courseCards=` (editorial | cinematic | paths; default → editorial).
 * Renders nothing while loading or when there are no courses, so the homepage is never blank-with-error.
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
  const query = useCourses({ per_page: 9 });
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
