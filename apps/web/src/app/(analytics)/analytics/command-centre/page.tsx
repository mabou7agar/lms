"use client";

import { useState } from "react";
import { AlertTriangle, BarChart3, Download } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useReportInsight } from "@/lib/reports/hooks";
import { downloadCsv, reportToCsv } from "@/lib/reports/csv";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { ReportView } from "@/components/reports/report-view";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

/**
 * The command centre: the three management views on one screen, sharing one date range.
 *
 * They are separate REPORTS rather than one giant payload because they answer to different people —
 * a director, a marketer and an accountant look at different numbers and trust them for different
 * reasons — and because each one is cached and exported independently.
 *
 * Rendering goes through the same shape-driven ReportView every other report uses, so a metric added
 * on the server appears here without a matching change in the UI. That is deliberate: the alternative
 * is a hand-built dashboard that silently omits whatever the backend learned to measure last.
 */
const TABS = [
  { key: "admin_summary", labelKey: "analytics.centre.tabs.executive" },
  { key: "marketing_funnel", labelKey: "analytics.centre.tabs.marketing" },
  { key: "accounting", labelKey: "analytics.centre.tabs.accounting" },
] as const;

type TabKey = (typeof TABS)[number]["key"];

export default function CommandCentrePage() {
  const { t } = useI18n();
  const [tab, setTab] = useState<TabKey>("admin_summary");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [applied, setApplied] = useState<{ from?: string; to?: string }>({});

  const query = useReportInsight(tab, { from: applied.from, to: applied.to, page: 1, perPage: 20 });

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow={t("analytics.centre.eyebrow")}
        icon="BarChart3"
        title={t("analytics.centre.title")}
        subtitle={t("analytics.centre.subtitle")}
      />

      <div className="flex flex-wrap gap-2">
        {TABS.map((item) => (
          <Button
            key={item.key}
            size="sm"
            variant={tab === item.key ? "default" : "outline"}
            onClick={() => setTab(item.key)}
          >
            {t(item.labelKey)}
          </Button>
        ))}
      </div>

      <form
        className="flex flex-wrap items-end gap-3 rounded-lg border bg-card p-4"
        onSubmit={(e) => {
          e.preventDefault();
          setApplied({ from: from || undefined, to: to || undefined });
        }}
        aria-label={t("reports.range")}
      >
        <label className="flex flex-col gap-1 text-sm">
          {t("reports.from")}
          <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-auto" />
        </label>
        <label className="flex flex-col gap-1 text-sm">
          {t("reports.to")}
          <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-auto" />
        </label>
        <Button type="submit" size="sm">
          {t("reports.apply")}
        </Button>
      </form>

      <QueryState query={query} isEmpty={(d) => !d.data || Object.keys(d.data).length === 0} empty={null}>
        {(res) => (
          <div className="space-y-4">
            {tab === "marketing_funnel" ? <TrackingNotice trackingSince={res.data?.tracking_since} /> : null}
            <div className="flex justify-end">
              <Button
                size="sm"
                variant="outline"
                onClick={() =>
                  downloadCsv(
                    `${tab}${applied.from ? `-${applied.from}` : ""}${applied.to ? `-${applied.to}` : ""}.csv`,
                    reportToCsv(res.data, res.meta),
                  )
                }
              >
                <Download className="me-2 size-4" aria-hidden />
                {t("analytics.centre.exportCsv")}
              </Button>
            </div>
            <ReportView payload={res.data} meta={res.meta} page={1} onPageChange={() => {}} />
          </div>
        )}
      </QueryState>
    </div>
  );
}

/**
 * The honest caveat on the funnel.
 *
 * View and click stages exist only from the moment the browser started reporting them. A window that
 * predates that shows zero, and a zero here means "not measured", not "nobody looked" — so the
 * screen says which it is rather than letting a reader draw a conclusion the data cannot support.
 */
function TrackingNotice({ trackingSince }: { trackingSince?: unknown }) {
  const { t, locale } = useI18n();

  if (typeof trackingSince === "string" && trackingSince !== "") {
    const since = new Date(trackingSince);
    const formatted = Number.isNaN(since.getTime())
      ? trackingSince
      : since.toLocaleDateString(locale === "ar" ? "ar" : "en", { dateStyle: "medium" });

    return (
      <p className="flex items-center gap-2 rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground">
        <BarChart3 className="size-4 shrink-0" aria-hidden />
        {t("analytics.centre.trackingSince").replace("{date}", formatted)}
      </p>
    );
  }

  return (
    <p
      role="status"
      className="flex items-center gap-2 rounded-lg border border-copper/40 bg-copper/5 p-3 text-xs"
    >
      <AlertTriangle className="size-4 shrink-0 text-copper" aria-hidden />
      {t("analytics.centre.noTracking")}
    </p>
  );
}
