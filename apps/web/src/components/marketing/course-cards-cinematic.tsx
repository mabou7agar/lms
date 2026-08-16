"use client";

import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale } from "@/config/theme";
import type { CourseListItem } from "@/lib/catalog/api";
import { Reveal } from "@/components/landing/reveal";
import { Button } from "@/components/ui/button";
import {
  CourseCover,
  CoverArt,
  InstructorAvatars,
  courseFamily,
  courseListItemToCover,
} from "@/components/marketing/course-cover";
import { FAMILY_LABEL } from "./course-cards-paths";

/**
 * Cinematic variant: one LARGE hero course (the first featured course) with a big cover image,
 * prominent serif title, subtitle, track + level labels, instructor medallions and a clear CTA —
 * followed by the remaining featured courses as smaller supporting <CourseCover> cards so the visual
 * identity stays consistent. Hero stacks above the grid on mobile; fully RTL-safe (logical props).
 */
export function CourseCardsCinematic({ courses }: { courses: CourseListItem[] }) {
  const { locale } = useI18n();
  const [hero, ...rest] = courses;
  if (!hero) return null;

  return (
    <div className="flex flex-col gap-8">
      <CinematicHero course={hero} />
      {rest.length > 0 ? (
        <div className="stagger-in grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {rest.map((course, i) => (
            <CourseCover
              key={course.id}
              course={courseListItemToCover(course, locale)}
              wave="cradle"
              index={i + 2}
            />
          ))}
        </div>
      ) : null}
    </div>
  );
}

function CinematicHero({ course }: { course: CourseListItem }) {
  const { locale } = useI18n();
  const cover = courseListItemToCover(course, locale);
  const family = courseFamily(course);

  const title = pickLocale(cover.title, locale);
  const subtitle = cover.subtitle ? pickLocale(cover.subtitle, locale) : null;
  const level = cover.level ? pickLocale(cover.level, locale) : null;
  const track = pickLocale(FAMILY_LABEL[family], locale);
  const eyebrow = locale === "ar" ? "الكورس المميّز" : "Featured course";
  const ctaLabel = locale === "ar" ? "عرض الكورس" : "View course";

  return (
    <Reveal>
      <article className="hb-hero-root">
        <div className="hb-hero-media">
          {cover.thumbnail ? (
            <span
              className="hb-hero-photo"
              style={{ backgroundImage: `url("${cover.thumbnail}")` }}
              aria-hidden="true"
            />
          ) : (
            <>
              <span
                className="hb-hero-photo"
                style={{ background: "radial-gradient(130% 100% at 70% -8%, #16273f 0%, #0f1b2e 62%, #0b1422 100%)" }}
                aria-hidden="true"
              />
              <CoverArt family={family} seed={cover.id} className="absolute inset-0 h-full w-full opacity-90" />
            </>
          )}
          <span className="hb-hero-scrim" aria-hidden="true" />
          <span className="hb-hero-eyebrow">{eyebrow}</span>
          <Link href={cover.href} aria-label={title} className="hb-hero-media-hit" />
        </div>

        <div className="hb-hero-body">
          <p className="hb-hero-kicker">
            {track}
            {level ? <span className="hb-hero-kicker-sep">·</span> : null}
            {level}
          </p>
          <h3 className="hb-hero-title">
            <Link href={cover.href} className="hb-hero-title-link">{title}</Link>
          </h3>
          {subtitle ? <p className="hb-hero-subtitle line-clamp-3">{subtitle}</p> : null}
          {cover.instructors.length > 0 ? (
            <div className="hb-hero-faculty">
              <InstructorAvatars instructors={cover.instructors} />
            </div>
          ) : null}
          <Button asChild size="lg" className="hb-hero-cta">
            <Link href={cover.href}>
              {ctaLabel}
              <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
            </Link>
          </Button>
        </div>
      </article>
    </Reveal>
  );
}
