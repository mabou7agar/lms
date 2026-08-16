"use client";

import { useState } from "react";
import { BarChart3 } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useCommerceAnalytics } from "@/lib/commerce/commerce-analytics-hooks";
import type { CommerceAnalyticsRange } from "@/lib/commerce/commerce-analytics";
import { AdminGuard } from "@/components/commerce/admin-guard";
import { KpiGrid } from "@/components/commerce/kpi-grid";
import { QueryState } from "@/components/student/query-state";
import { EmptyState } from "@/components/states/empty-state";

/** Format a Date as a `YYYY-MM-DD` string in local time (the shape the analytics endpoint expects). */
function toDateInput(date: Date): string {
  const year = date.getFullYear();
  const month = `${date.getMonth() + 1}`.padStart(2, "0");
  const day = `${date.getDate()}`.padStart(2, "0");
  return `${year}-${month}-${day}`;
}

/** Default window: the trailing 30 days, ending today. */
function defaultRange(): CommerceAnalyticsRange {
  const to = new Date();
  const from = new Date();
  from.setDate(from.getDate() - 29);
  return { from: toDateInput(from), to: toDateInput(to) };
}

function AnalyticsView() {
  const { t } = useI18n();
  const [range, setRange] = useState<CommerceAnalyticsRange>(defaultRange);
  const query = useCommerceAnalytics(range);

  return (
    <>
      <header className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-serif text-3xl font-semibold tracking-tight sm:text-4xl">
            {t("commerce.analytics.title")}
          </h1>
        </div>

        <fieldset className="flex flex-col gap-1.5">
          <legend className="mb-1 text-sm font-medium text-muted-foreground">
            {t("commerce.analytics.range")}
          </legend>
          <div className="flex items-center gap-2">
            <input
              type="date"
              value={range.from}
              max={range.to}
              aria-label={t("commerce.analytics.range")}
              onChange={(event) => setRange((prev) => ({ ...prev, from: event.target.value }))}
              className="rounded-md border bg-background px-3 py-1.5 text-sm tabular-nums"
            />
            <span aria-hidden className="text-muted-foreground">
              –
            </span>
            <input
              type="date"
              value={range.to}
              min={range.from}
              aria-label={t("commerce.analytics.range")}
              onChange={(event) => setRange((prev) => ({ ...prev, to: event.target.value }))}
              className="rounded-md border bg-background px-3 py-1.5 text-sm tabular-nums"
            />
          </div>
        </fieldset>
      </header>

      <QueryState
        query={query}
        isEmpty={(d) => d.orders_count === 0 && d.revenue_minor === 0 && d.active_subscribers === 0}
        empty={<EmptyState icon={<BarChart3 className="size-8" />} title={t("commerce.analytics.title")} />}
      >
        {(data) => <KpiGrid analytics={data} />}
      </QueryState>
    </>
  );
}

export default function AnalyticsPage() {
  return (
    <AdminGuard>
      <AnalyticsView />
    </AdminGuard>
  );
}
