"use client";

import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale } from "@/config/theme";
import type { FeaturedCoursesContent, HomepageSection } from "@/lib/homepage/api";
import { BlockShell, BlockHeading } from "@/components/homepage/block-shell";
import { proxyMediaUrl } from "@/lib/media/proxy";
import { Button } from "@/components/ui/button";

/** CMS featured-courses grid. Consumes the server-resolved courses (no client fetch). Bilingual, RTL-safe. */
export function FeaturedCoursesBlock({ section }: { section: HomepageSection }) {
  const { locale } = useI18n();
  const content = section.content as FeaturedCoursesContent;
  const courses = section.resolved?.courses ?? [];
  if (courses.length === 0) return null;

  return (
    <BlockShell section={section} id="featured-courses">
      <BlockHeading heading={content.heading} subheading={content.subheading} />
      <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        {courses.map((course) => (
          <li key={course.id}>
            <Link
              href={course.href}
              className="group flex h-full flex-col overflow-hidden rounded-2xl border border-border bg-card transition-shadow hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              {course.thumbnail ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={proxyMediaUrl(course.thumbnail)} alt="" width={640} height={360} className="aspect-video w-full object-cover" loading="lazy" decoding="async" />
              ) : (
                <div className="aspect-video w-full bg-primary/10" aria-hidden />
              )}
              <div className="flex flex-1 flex-col p-5">
                {course.level ? (
                  <span className="mb-2 text-xs font-semibold uppercase tracking-wide text-copper">{course.level}</span>
                ) : null}
                <span className="font-serif text-lg font-semibold text-foreground">
                  {course.title ? pickLocale(course.title, locale) : course.slug}
                </span>
                {course.subtitle ? (
                  <span className="mt-2 line-clamp-2 text-sm text-muted-foreground">{pickLocale(course.subtitle, locale)}</span>
                ) : null}
              </div>
            </Link>
          </li>
        ))}
      </ul>
      {content.cta?.href ? (
        <div className="mt-10 text-center">
          <Button asChild size="lg" variant="outline">
            <Link href={content.cta.href}>
              {content.cta.label ? pickLocale(content.cta.label, locale) : ""}
              <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
            </Link>
          </Button>
        </div>
      ) : null}
    </BlockShell>
  );
}
