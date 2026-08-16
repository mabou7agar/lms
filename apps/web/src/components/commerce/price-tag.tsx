"use client";

import type { DisplayPrice } from "@/lib/commerce/sales-format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { cn } from "@/lib/utils";

/**
 * The price a buyer pays, with the pre-sale amount struck through when a sale is running. Used on
 * course cards, the course page and bundle surfaces so a price always reads the same way.
 *
 * `price` is null when nothing sells the item; the caller decides what to render instead, because
 * "not on sale yet" reads differently on a card than on a detail page.
 */
export function PriceTag({
  price,
  size = "md",
  className,
}: {
  price: DisplayPrice | null;
  size?: "sm" | "md" | "lg";
  className?: string;
}) {
  const { t } = useI18n();
  if (!price) return null;

  const effective = size === "lg" ? "text-3xl" : size === "sm" ? "text-base" : "text-xl";
  const original = size === "lg" ? "text-base" : "text-sm";

  return (
    <div className={cn("flex flex-wrap items-baseline gap-2", className)}>
      <span className={cn("font-serif font-semibold tabular-nums text-foreground", effective)}>
        {price.effective}
      </span>
      {price.original ? (
        <>
          <span className={cn("text-muted-foreground line-through tabular-nums", original)}>
            {price.original}
          </span>
          <span className="rounded-full bg-copper/10 px-2 py-0.5 text-xs font-medium text-copper">
            {t("commerce.products.sale")}
          </span>
        </>
      ) : null}
    </div>
  );
}
