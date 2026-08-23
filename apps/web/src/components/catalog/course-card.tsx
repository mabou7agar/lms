"use client";

import Link from "next/link";
import { Star, ArrowRight } from "lucide-react";
import type { CourseListItem } from "@/lib/catalog/api";
import { coursePurchasePrice } from "@/lib/commerce/sales-format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Badge } from "@/components/ui/badge";
import { PriceTag } from "@/components/commerce/price-tag";
import { CourseMedia } from "./course-media";

/**
 * Premium public course card (catalog + rails). Uses only real list fields — title, subtitle,
 * level, language, featured, and the course's real price — never a fabricated rating or figure.
 * RTL-safe (logical properties); reduced-motion safe (transitions only).
 *
 * A course with no active product is free-enrollable, matching the Learning API contract.
 */
export function CourseCard({ course }: { course: CourseListItem }) {
  const { t, locale } = useI18n();
  const price = coursePurchasePrice(course.purchase, locale);
  // `purchase` is absent on payloads that never attached a summary; only an explicit
  // `purchasable: false` means the course is genuinely not for sale.
  const free = course.purchase?.purchasable === false;

  return (
    <Link
      href={`/courses/${course.slug || course.id}`}
      className="group relative flex h-full flex-col overflow-hidden rounded-2xl border border-border bg-card transition-all duration-300 hover:-translate-y-1 hover:border-primary/30 hover:shadow-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
    >
      <div className="relative overflow-hidden">
        <CourseMedia src={course.thumbnail_path} title={course.title} className="transition-transform duration-500 group-hover:scale-[1.04]" />
        <div className="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/25 via-transparent to-transparent" aria-hidden />
        {course.is_featured ? (
          <Badge variant="warning" className="absolute end-3 top-3 gap-1 shadow-sm">
            <Star className="size-3 fill-current" aria-hidden /> {t("catalog.course.featured")}
          </Badge>
        ) : null}
      </div>

      <div className="flex flex-1 flex-col p-5">
        {course.level || course.language ? (
          <div className="mb-2.5 flex flex-wrap gap-1.5">
            {course.level ? <Badge variant="secondary">{course.level}</Badge> : null}
            {course.language ? <Badge variant="outline">{course.language}</Badge> : null}
          </div>
        ) : null}

        <h3 className="line-clamp-2 font-serif text-lg font-semibold leading-tight text-foreground transition-colors group-hover:text-primary">
          {course.title}
        </h3>
        {course.subtitle ? (
          <p className="mt-1.5 line-clamp-2 text-sm leading-relaxed text-muted-foreground">{course.subtitle}</p>
        ) : null}

        {/* Price sits above the CTA on its own baseline so it reads first — it is the decision. */}
        <div className="mt-4 flex flex-wrap items-end justify-between gap-x-3 gap-y-2 pt-1">
          {price ? (
            <PriceTag price={price} size="sm" />
          ) : free ? (
            <span className="text-sm font-semibold text-primary">{t("catalog.course.free")}</span>
          ) : (
            <span aria-hidden />
          )}
          <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary">
            {t("catalog.course.view")}
            <ArrowRight className="size-4 transition-transform duration-300 group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" aria-hidden />
          </span>
        </div>
      </div>
    </Link>
  );
}
