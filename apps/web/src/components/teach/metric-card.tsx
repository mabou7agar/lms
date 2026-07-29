"use client";

import { Info } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { MetricValue } from "@/lib/teach/api";
import { formatMetric, type MetricFormat } from "@/lib/teach/format";

export interface MetricCardProps {
  label: string;
  metric: MetricValue | undefined;
  format?: MetricFormat;
  icon?: LucideIcon;
}

/**
 * One overview metric, rendered through the backend's availability envelope.
 *
 * The rule this component exists to enforce: an unavailable metric shows the word "Unavailable"
 * and the server's own reason — never 0, never "—", never a dash that could be mistaken for a
 * value. An instructor with no revenue backend must not read "$0" and conclude they earned nothing.
 *
 * The unavailable state is also distinguished by more than colour (muted text is not enough): the
 * literal word "Unavailable" carries the meaning for anyone who cannot perceive the styling.
 */
export function MetricCard({ label, metric, format = "number", icon: Icon }: MetricCardProps) {
  const { t, locale } = useI18n();
  const formatted = formatMetric(metric, format, locale);
  const reason = metric?.reason;

  return (
    <Card className="card-hover h-full hover:border-primary/30 hover:elevation-3">
      <CardContent className="flex h-full items-start gap-4 p-5">
        {Icon ? (
          <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
            <Icon className="size-5" aria-hidden />
          </div>
        ) : null}

        <div className="min-w-0 flex-1">
          {formatted === null ? (
            <p className="text-base font-medium text-muted-foreground">
              {t("teach.metric.unavailable")}
            </p>
          ) : (
            <p className="text-2xl font-bold tabular-nums">{formatted}</p>
          )}

          <div className="flex items-center gap-1.5">
            <span className="truncate text-sm text-muted-foreground">{label}</span>

            {formatted === null && reason ? (
              <Tooltip>
                <TooltipTrigger asChild>
                  <button
                    type="button"
                    // The reason is also rendered as visible text below on small screens; this is
                    // the pointer/keyboard affordance, not the only way to reach it.
                    className="rounded-full text-muted-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    aria-label={`${label}: ${reason}`}
                  >
                    <Info className="size-3.5" aria-hidden />
                  </button>
                </TooltipTrigger>
                <TooltipContent>{reason}</TooltipContent>
              </Tooltip>
            ) : null}
          </div>

          {formatted === null && reason ? (
            <p className="mt-1 text-xs leading-snug text-muted-foreground">{reason}</p>
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}
