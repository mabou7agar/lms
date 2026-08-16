"use client";

import Link from "next/link";
import { ArrowRight, Layers } from "lucide-react";
import type { Product } from "@/lib/commerce/api";
import { accessLabel, audienceLabels, defaultPrice, displayPrice, seatLabel } from "@/lib/commerce/sales-format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Badge } from "@/components/ui/badge";
import { PriceTag } from "@/components/commerce/price-tag";

/**
 * A bundle in the public catalogue. Shows only facts the product actually carries — how many courses
 * it grants, its real price, who may buy it, and the seat count when a company can. Anything the
 * product does not define is omitted rather than filled with a placeholder.
 */
export function BundleCard({ bundle }: { bundle: Product }) {
  const { t, locale } = useI18n();
  const price = displayPrice(defaultPrice(bundle), locale);
  const courseCount = bundle.courses?.length ?? 0;
  const access = accessLabel(bundle.access, locale);
  const seats = seatLabel(bundle.seats, locale);
  const audiences = audienceLabels(bundle.audience, locale);

  return (
    <Link
      href={`/bundles/${bundle.id}`}
      className="group flex h-full flex-col rounded-2xl border border-border bg-card p-6 transition-all duration-300 hover:-translate-y-1 hover:border-primary/30 hover:shadow-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
    >
      <div className="flex items-center gap-2 text-copper">
        <Layers className="size-4" aria-hidden />
        {courseCount > 0 ? (
          <span className="text-xs font-semibold uppercase tracking-[0.14em]">
            {courseCount} {t("commerce.bundles.includedCount")}
          </span>
        ) : null}
      </div>

      <h3 className="mt-3 line-clamp-2 font-serif text-lg font-semibold leading-tight transition-colors group-hover:text-primary">
        {bundle.title}
      </h3>
      {bundle.description ? (
        <p className="mt-2 line-clamp-3 text-sm leading-relaxed text-muted-foreground">{bundle.description}</p>
      ) : null}

      {audiences.length > 0 || access || seats ? (
        <div className="mt-4 flex flex-wrap gap-1.5">
          {audiences.map((label) => (
            <Badge key={label} variant="secondary">{label}</Badge>
          ))}
          {access ? <Badge variant="outline">{access}</Badge> : null}
          {seats ? <Badge variant="outline">{seats}</Badge> : null}
        </div>
      ) : null}

      <div className="mt-auto flex flex-wrap items-end justify-between gap-x-3 gap-y-2 pt-5">
        <PriceTag price={price} size="sm" />
        <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary">
          {t("commerce.bundles.view")}
          <ArrowRight className="size-4 transition-transform duration-300 group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" aria-hidden />
        </span>
      </div>
    </Link>
  );
}
