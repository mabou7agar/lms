"use client";

import { Star } from "lucide-react";
import { cn } from "@/lib/utils";
import { useCommunityI18n } from "@/lib/community/community-i18n";

interface StarRatingProps {
  /** Current value, 0–5. */
  value: number;
  /** When provided the component becomes an interactive rating input. */
  onChange?: (value: number) => void;
  className?: string;
  size?: "sm" | "md" | "lg";
}

const SIZES = { sm: "size-3.5", md: "size-4", lg: "size-6" } as const;

/**
 * Star rating — a read-only display, or (with `onChange`) an accessible 1–5 rating input built from
 * real radio buttons. Logical layout keeps it correct under RTL.
 */
export function StarRating({ value, onChange, className, size = "md" }: StarRatingProps) {
  const { t } = useCommunityI18n();
  const starClass = SIZES[size];

  if (!onChange) {
    return (
      <div className={cn("inline-flex items-center gap-0.5", className)} aria-label={t("reviews.outOfFive", { rating: value })}>
        {[1, 2, 3, 4, 5].map((n) => (
          <Star
            key={n}
            className={cn(starClass, n <= Math.round(value) ? "fill-warning text-warning" : "fill-transparent text-muted-foreground/40")}
            aria-hidden
          />
        ))}
      </div>
    );
  }

  return (
    <div role="radiogroup" aria-label={t("reviews.yourRating")} className={cn("inline-flex items-center gap-0.5", className)}>
      {[1, 2, 3, 4, 5].map((n) => (
        <button
          key={n}
          type="button"
          role="radio"
          aria-checked={value === n}
          aria-label={t("reviews.ratingAria", { stars: n })}
          onClick={() => onChange(n)}
          className="rounded-sm p-0.5 text-warning transition-transform hover:scale-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <Star className={cn(starClass, n <= value ? "fill-warning text-warning" : "fill-transparent text-muted-foreground/50")} aria-hidden />
        </button>
      ))}
    </div>
  );
}
