"use client";

import { CheckCircle2, Clock, Info, OctagonAlert, TriangleAlert, UserX } from "lucide-react";
import Link from "next/link";
import type { ReactNode } from "react";
import { QueryState } from "@/components/student/query-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { InstructorAlerts, ReadinessCoverage, UnavailableSignal } from "@/lib/teach/api";
import { useInstructorAlerts } from "@/lib/teach/hooks";
import { formatDate, formatNumber } from "@/lib/teach/format";

export interface AlertsSectionProps {
  onReviewReadiness: (courseId: string) => void;
}

function AlertGroup({
  title,
  icon: Icon,
  tone,
  children,
}: {
  title: string;
  icon: typeof OctagonAlert;
  tone: "destructive" | "warning" | "muted";
  children: ReactNode;
}) {
  const toneClass =
    tone === "destructive"
      ? "text-destructive"
      : tone === "warning"
        ? "text-warning"
        : "text-muted-foreground";

  return (
    <section className="space-y-2">
      <h3 className="flex items-center gap-2 text-sm font-semibold">
        <Icon className={`size-4 ${toneClass}`} aria-hidden />
        {title}
      </h3>
      {children}
    </section>
  );
}

/**
 * Explains that only part of the catalogue was checked.
 *
 * This is the single most important element in the panel. Readiness is bounded server-side, so an
 * instructor with more courses than the limit sees an answer drawn from a subset. Without this
 * banner, "no publish blockers" reads as a clean bill of health for a catalogue that was never
 * fully examined — the exact false reassurance the backend's coverage metadata exists to prevent.
 */
function CoverageNotice({ coverage }: { coverage: ReadinessCoverage }) {
  const { t, locale } = useI18n();

  if (!coverage.truncated) return null;

  return (
    <div
      className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm"
      role="status"
    >
      <Info className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
      <p>
        {t("teach.alerts.truncated")
          .replace("{evaluated}", formatNumber(coverage.evaluated_count, locale))
          .replace("{total}", formatNumber(coverage.total_count, locale))}
      </p>
    </div>
  );
}

/** An alert stream the backend cannot compute. Says why, instead of rendering an empty list. */
function UnavailableNotice({ label, signal }: { label: string; signal: UnavailableSignal }) {
  return (
    <div className="flex items-start gap-2 rounded-lg border border-dashed p-3 text-sm">
      <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
      <div>
        <p className="font-medium">{label}</p>
        <p className="text-xs text-muted-foreground">{signal.reason}</p>
      </div>
    </div>
  );
}

export function AlertsSection({ onReviewReadiness }: AlertsSectionProps) {
  const { t, locale } = useI18n();
  const query = useInstructorAlerts();

  return (
    <QueryState<InstructorAlerts>
      query={query}
      loading={<Skeleton variant="card" className="h-64" />}
    >
      {(alerts) => {
        const nothingActionable =
          alerts.publish_blockers.length === 0 &&
          alerts.warnings.length === 0 &&
          alerts.stale_drafts.length === 0 &&
          alerts.courses_without_learners.length === 0;

        return (
          <Card>
            <CardContent className="space-y-5 p-4 sm:p-5">
              <CoverageNotice coverage={alerts.readiness_coverage} />

              {nothingActionable && !alerts.readiness_coverage.truncated ? (
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                  <CheckCircle2 className="size-4 text-success" aria-hidden />
                  {t("teach.alerts.allClear")}
                </div>
              ) : null}

              {alerts.publish_blockers.length > 0 ? (
                <AlertGroup
                  title={`${t("teach.alerts.publishBlockers")} (${alerts.publish_blockers.length})`}
                  icon={OctagonAlert}
                  tone="destructive"
                >
                  <ul className="space-y-2">
                    {alerts.publish_blockers.map((course) => (
                      <li key={course.id} className="rounded-lg border p-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <Link
                            href={`/teach/courses/${course.id}`}
                            className="font-medium hover:underline"
                          >
                            {course.title}
                          </Link>
                          <Badge variant="destructive">
                            {course.blocker_count} {t("teach.readiness.blockersShort")}
                          </Badge>
                        </div>
                        {course.first_blocker ? (
                          <p className="mt-1 text-sm text-muted-foreground">{course.first_blocker}</p>
                        ) : null}
                        <Button
                          variant="link"
                          size="sm"
                          className="h-auto p-0"
                          onClick={() => onReviewReadiness(course.id)}
                        >
                          {t("teach.readiness.review")}
                        </Button>
                      </li>
                    ))}
                  </ul>
                </AlertGroup>
              ) : null}

              {alerts.warnings.length > 0 ? (
                <AlertGroup
                  title={`${t("teach.alerts.warnings")} (${alerts.warnings.length})`}
                  icon={TriangleAlert}
                  tone="warning"
                >
                  <ul className="space-y-1.5">
                    {alerts.warnings.map((course) => (
                      <li key={course.id} className="flex items-center justify-between gap-2 text-sm">
                        <Link
                          href={`/teach/courses/${course.id}`}
                          className="truncate hover:underline"
                        >
                          {course.title}
                        </Link>
                        <Badge variant="warning">
                          {course.warning_count} {t("teach.readiness.warningsShort")}
                        </Badge>
                      </li>
                    ))}
                  </ul>
                </AlertGroup>
              ) : null}

              {alerts.stale_drafts.length > 0 ? (
                <AlertGroup
                  title={`${t("teach.alerts.staleDrafts")} (${alerts.stale_drafts.length})`}
                  icon={Clock}
                  tone="muted"
                >
                  <ul className="space-y-1.5">
                    {alerts.stale_drafts.map((course) => (
                      <li key={course.id} className="flex items-center justify-between gap-2 text-sm">
                        <Link
                          href={`/teach/courses/${course.id}/edit`}
                          className="truncate hover:underline"
                        >
                          {course.title}
                        </Link>
                        <span className="shrink-0 text-xs text-muted-foreground">
                          {formatDate(course.last_updated_at, locale) ?? "—"}
                        </span>
                      </li>
                    ))}
                  </ul>
                </AlertGroup>
              ) : null}

              {alerts.courses_without_learners.length > 0 ? (
                <AlertGroup
                  title={`${t("teach.alerts.noLearners")} (${alerts.courses_without_learners.length})`}
                  icon={UserX}
                  tone="muted"
                >
                  <ul className="space-y-1.5">
                    {alerts.courses_without_learners.map((course) => (
                      <li key={course.id} className="text-sm">
                        <Link
                          href={`/teach/courses/${course.id}`}
                          className="hover:underline"
                        >
                          {course.title}
                        </Link>
                      </li>
                    ))}
                  </ul>
                </AlertGroup>
              ) : null}

              <div className="space-y-2 border-t pt-4">
                <UnavailableNotice
                  label={t("teach.metric.atRisk")}
                  signal={alerts.at_risk_learners}
                />
                <UnavailableNotice
                  label={t("teach.alerts.failedPublishes")}
                  signal={alerts.failed_publishes}
                />
              </div>
            </CardContent>
          </Card>
        );
      }}
    </QueryState>
  );
}
