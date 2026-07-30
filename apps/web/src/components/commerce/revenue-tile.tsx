"use client";

import type { ReactNode } from "react";
import { Card, CardContent } from "@/components/ui/card";

/** Emphasis of the value, mapped to a semantic text color. `default` inherits the foreground. */
export type RevenueTileTone = "default" | "positive" | "negative";

export type RevenueTileProps = {
  /** Localized KPI name, rendered as the tile's label. */
  label: string;
  /** Pre-formatted, locale-aware value (money via formatMoney, or a formatted count). */
  value: string;
  /** Optional secondary line under the value (e.g. the reporting window). */
  hint?: string;
  /** Optional decorative leading icon; hidden from assistive tech. */
  icon?: ReactNode;
  tone?: RevenueTileTone;
};

const toneClass: Record<RevenueTileTone, string> = {
  default: "text-foreground",
  positive: "text-success",
  negative: "text-destructive",
};

/**
 * A single KPI tile: label, a large tabular-numbers value, and an optional hint. Presentational and
 * self-contained — all i18n and money/number formatting happen in the parent so the tile only ever
 * renders already-localized strings. Value carries `tabular-nums` so a grid of tiles stays aligned.
 */
export function RevenueTile({ label, value, hint, icon, tone = "default" }: RevenueTileProps) {
  return (
    <Card className="card-hover hover:border-primary/30">
      <CardContent className="space-y-2 p-5">
        <div className="flex items-center justify-between gap-2">
          <p className="text-sm text-muted-foreground">{label}</p>
          {icon ? (
            <span className="text-muted-foreground" aria-hidden>
              {icon}
            </span>
          ) : null}
        </div>
        <p className={`font-serif text-2xl font-semibold tabular-nums ${toneClass[tone]}`}>{value}</p>
        {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
      </CardContent>
    </Card>
  );
}
