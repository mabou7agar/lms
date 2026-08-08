"use client";

import type { ReactNode } from "react";
import { Award, BookOpen, Clock, ListChecks, TrendingUp } from "lucide-react";
import { QueryState } from "@/components/student/query-state";
import { PageHeader } from "@/components/student/page-header";
import { StatCard } from "@/components/student/stat-card";
import { EmptyState } from "@/components/states/empty-state";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { Locale } from "@/lib/i18n/config";
import type { LearnerProgress } from "@/lib/teach/api";
import { useLearnerProgress } from "@/lib/teach/hooks";
import { durationParts, formatDate, formatDateTime, formatNumber, formatPercent } from "@/lib/teach/format";

/** The translate function shape from the i18n context (not exported there, mirrored locally). */
type Translate = (key: string) => string;

/** Watch time as a single "Xh Ym" / "Ym" string (see the analytics section for the same idiom). */
function watchTimeLabel(seconds: number, locale: Locale, t: Translate): string {
  const { hours, minutes } = durationParts(seconds);
  const h = `${formatNumber(hours, locale)}${t("teach.analytics.hoursShort")}`;
  const m = `${formatNumber(minutes, locale)}${t("teach.analytics.minutesShort")}`;
  return hours > 0 ? `${h} ${m}` : m;
}

/** One label/value row in the learner detail card. */
function DetailRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 py-2.5 last:border-0">
      <span className="text-sm text-muted-foreground">{label}</span>
      <span className="text-sm font-medium">{children}</span>
    </div>
  );
}

/**
 * One learner's drill-down for one course: progress, watch time, lessons, current lesson, activity dates,
 * the required-assessment outcome and certificate status. Single fetch — the whole report is assembled
 * server-side, so there is no per-assessment fan-out here.
 */
export function LearnerProgressPanel({ courseId, studentId }: { courseId: string; studentId: string }) {
  const { t, locale } = useI18n();
  const query = useLearnerProgress(courseId, studentId);

  return (
    <QueryState<LearnerProgress>
      query={query}
      isEmpty={(d) => !d}
      empty={<EmptyState title={t("teach.learner.notFound")} />}
    >
      {(learner) => {
        const { assessments } = learner;
        return (
          <div className="space-y-6">
            <PageHeader
              eyebrow="INSTRUCTOR"
              icon="User"
              title={learner.student.name ?? t("teach.learner.title")}
              subtitle={t("teach.learner.subtitle")}
            />

            <section aria-label={t("teach.learner.title")}>
              <div className="stagger-in grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                  label={t("teach.learner.progress")}
                  value={formatPercent(learner.percent_complete, locale)}
                  icon={TrendingUp}
                />
                <StatCard
                  label={t("teach.learner.watchTime")}
                  value={watchTimeLabel(learner.watched_seconds, locale, t)}
                  icon={Clock}
                />
                <StatCard
                  label={t("teach.learner.lessons")}
                  value={`${formatNumber(learner.lessons_completed, locale)} / ${formatNumber(learner.lessons_total, locale)}`}
                  icon={ListChecks}
                />
                <StatCard
                  label={t("teach.learner.currentLesson")}
                  value={learner.current_lesson?.title ?? t("teach.learner.noCurrentLesson")}
                  icon={BookOpen}
                />
              </div>
            </section>

            <Card>
              <CardContent className="p-5">
                <DetailRow label={t("teach.learner.lastActivity")}>
                  {formatDateTime(learner.last_activity_at, locale) ?? t("teach.learner.never")}
                </DetailRow>
                <DetailRow label={t("teach.learner.startedAt")}>
                  {formatDate(learner.started_at, locale) ?? t("teach.learner.never")}
                </DetailRow>
                <DetailRow label={t("teach.learner.completedAt")}>
                  {formatDate(learner.completed_at, locale) ?? "—"}
                </DetailRow>

                <DetailRow label={t("teach.learner.assessments")}>
                  {assessments.required === 0 ? (
                    <span className="text-muted-foreground">{t("teach.learner.noAssessments")}</span>
                  ) : (
                    <span className="flex items-center gap-2">
                      {t("teach.learner.assessmentsSummary")
                        .replace("{passed}", formatNumber(assessments.passed, locale))
                        .replace("{required}", formatNumber(assessments.required, locale))}
                      <Badge variant={assessments.all_required_passed ? "success" : "secondary"}>
                        {assessments.all_required_passed
                          ? t("teach.learner.allPassed")
                          : t("teach.learner.notAllPassed")}
                      </Badge>
                    </span>
                  )}
                </DetailRow>

                <DetailRow label={t("teach.learner.certificate")}>
                  <Badge variant={learner.certificate.issued ? "success" : "outline"} className="gap-1">
                    <Award className="size-3.5" aria-hidden />
                    {learner.certificate.issued
                      ? t("teach.learner.certificateIssued")
                      : t("teach.learner.certificateNotIssued")}
                  </Badge>
                </DetailRow>
              </CardContent>
            </Card>
          </div>
        );
      }}
    </QueryState>
  );
}
