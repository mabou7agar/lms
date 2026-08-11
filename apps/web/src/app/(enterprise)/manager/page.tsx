"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { Download } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { track } from "@/lib/analytics/track";
import {
  reportExportUrl,
  type ReportScope,
} from "@/lib/enterprise/manager-api";
import { useDepartments, useManagerReport, useSeatSummary, useTeams } from "@/lib/enterprise/manager-hooks";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { ReportMetrics } from "@/components/enterprise/report-metrics";
import { SectionCard } from "@/components/org/section-card";
import { Field } from "@/components/auth/field";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Progress } from "@/components/ui/progress";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const ALL = "all";

/** Seat-utilization panel (purchased / used / available). */
function SeatPanel() {
  const { t } = useI18n();
  const query = useSeatSummary();

  return (
    <SectionCard title={t("manager.seats.title")}>
      <QueryState query={query}>
        {(summary) =>
          summary ? (
            <div className="space-y-4">
              <div className="grid grid-cols-3 gap-3 text-center">
                <div>
                  <div className="font-serif text-2xl font-bold tabular-nums">{summary.seats.purchased}</div>
                  <div className="text-xs text-muted-foreground">{t("manager.seats.purchased")}</div>
                </div>
                <div>
                  <div className="font-serif text-2xl font-bold tabular-nums">{summary.seats.used}</div>
                  <div className="text-xs text-muted-foreground">{t("manager.seats.used")}</div>
                </div>
                <div>
                  <div className="font-serif text-2xl font-bold tabular-nums">{summary.seats.available}</div>
                  <div className="text-xs text-muted-foreground">{t("manager.seats.available")}</div>
                </div>
              </div>
              <Progress
                value={summary.seats.purchased > 0 ? (summary.seats.used / summary.seats.purchased) * 100 : 0}
                label={t("manager.seats.utilization")}
              />
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">{t("manager.seats.noSubscription")}</p>
          )
        }
      </QueryState>
    </SectionCard>
  );
}

export default function ManagerDashboardPage() {
  const { t, locale } = useI18n();

  // Non-PII page-view event (locale is the i18n Locale type). Fired once, as an external-system sync.
  const fired = useRef(false);
  useEffect(() => {
    if (fired.current) return;
    fired.current = true;
    track("page_view", { locale, path: "/manager" });
  }, [locale]);

  const [scopeSel, setScopeSel] = useState<string>(ALL);
  const [inactiveInput, setInactiveInput] = useState<string>("");
  const [appliedInactive, setAppliedInactive] = useState<number | undefined>(undefined);

  const departments = useDepartments();
  const teams = useTeams();

  const scope = useMemo<ReportScope>(() => {
    const s: ReportScope = {};
    if (scopeSel.startsWith("dept:")) s.department_id = scopeSel.slice(5);
    else if (scopeSel.startsWith("team:")) s.team_id = scopeSel.slice(5);
    if (typeof appliedInactive === "number") s.inactive_days = appliedInactive;
    return s;
  }, [scopeSel, appliedInactive]);

  const report = useManagerReport(scope);

  const applyInactive = () => {
    const parsed = Number.parseInt(inactiveInput, 10);
    setAppliedInactive(Number.isFinite(parsed) && parsed > 0 ? parsed : undefined);
  };

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow={t("manager.dashboard.eyebrow")}
        icon="LayoutDashboard"
        title={t("manager.dashboard.title")}
        subtitle={t("manager.dashboard.subtitle")}
        action={
          <Button asChild variant="outline" size="sm">
            <a href={reportExportUrl(scope)}>
              <Download className="size-4" aria-hidden /> {t("manager.dashboard.export")}
            </a>
          </Button>
        }
      />

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-1">
          <SeatPanel />
        </div>

        <div className="lg:col-span-2">
          <SectionCard title={t("manager.dashboard.scope")}>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field id="scope-select" label={t("manager.dashboard.scope")}>
                <Select value={scopeSel} onValueChange={setScopeSel}>
                  <SelectTrigger id="scope-select">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ALL}>{t("manager.dashboard.allOrg")}</SelectItem>
                    {(departments.data?.data ?? []).map((d) => (
                      <SelectItem key={`dept-${d.id}`} value={`dept:${d.id}`}>
                        {t("manager.dashboard.department")}: {d.name}
                      </SelectItem>
                    ))}
                    {(teams.data?.data ?? []).map((tm) => (
                      <SelectItem key={`team-${tm.id}`} value={`team:${tm.id}`}>
                        {t("manager.dashboard.team")}: {tm.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>

              <Field id="inactive-days" label={t("manager.dashboard.inactiveDays")}>
                <div className="flex gap-2">
                  <Input
                    id="inactive-days"
                    type="number"
                    min={1}
                    inputMode="numeric"
                    value={inactiveInput}
                    onChange={(e) => setInactiveInput(e.target.value)}
                  />
                  <Button type="button" variant="outline" onClick={applyInactive}>
                    {t("manager.dashboard.apply")}
                  </Button>
                </div>
              </Field>
            </div>
          </SectionCard>
        </div>
      </div>

      <section aria-label={t("manager.report.title")} className="space-y-4">
        <h2 className="text-sm font-semibold text-muted-foreground">{t("manager.report.title")}</h2>
        <QueryState query={report}>{(data) => <ReportMetrics report={data} />}</QueryState>
      </section>
    </div>
  );
}
