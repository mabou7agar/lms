"use client";

import { Award, Clock, TrendingDown, UserMinus, Users } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { QueryState } from "@/components/student/query-state";
import { EmptyState } from "@/components/states/empty-state";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { Locale } from "@/lib/i18n/config";
import type { CourseAnalytics, MetricValue } from "@/lib/teach/api";
import { COMPLETION_BUCKETS } from "@/lib/teach/api";
import { useCourseAnalytics } from "@/lib/teach/hooks";
import { durationParts, formatNumber } from "@/lib/teach/format";
import { MetricCard } from "./metric-card";

/** The translate function shape from the i18n context (not exported there, mirrored locally). */
type Translate = (key: string) => string;

/**
 * A watch-time figure as "Xh Ym" (or "Ym" under an hour), composed into a SINGLE string so it lands in
 * one text node — a split "2" / "h" / "30" / "m" would defeat both screen readers and simple assertions.
 */
function watchTimeLabel(seconds: number, locale: Locale, t: Translate): string {
  const { hours, minutes } = durationParts(seconds);
  const h = `${formatNumber(hours, locale)}${t("teach.analytics.hoursShort")}`;
  const m = `${formatNumber(minutes, locale)}${t("teach.analytics.minutesShort")}`;
  return hours > 0 ? `${h} ${m}` : m;
}

/**
 * A watch-time metric card. Mirrors {@link MetricCard} but formats seconds as h/m rather than a raw
 * count, and honours the same availability envelope: an unavailable metric shows the word "Unavailable"
 * and the server reason, never a zero that would read as "watched nothing".
 */
function WatchTimeCard({ label, metric, icon: Icon }: { label: string; metric: MetricValue; icon: LucideIcon }) {
  const { t, locale } = useI18n();
  const text = metric.available && metric.value !== null ? watchTimeLabel(metric.value, locale, t) : null;

  return (
    <Card className="group h-full border-border/70 transition-all duration-300 hover:-translate-y-0.5 hover:border-copper/30 hover:shadow-lg">
      <CardContent className="flex h-full items-start gap-4 p-5">
        <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-copper/10 text-copper transition-transform duration-300 group-hover:scale-105">
          <Icon className="size-5" aria-hidden />
        </div>
        <div className="min-w-0 flex-1">
          {text === null ? (
            <p className="text-base font-medium text-muted-foreground">{t("teach.analytics.unavailable")}</p>
          ) : (
            <p className="font-serif text-3xl font-bold tabular-nums leading-none">{text}</p>
          )}
          <span className="truncate text-sm text-muted-foreground">{label}</span>
          {text === null && metric.reason ? (
            <p className="mt-1 text-xs leading-snug text-muted-foreground">{metric.reason}</p>
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}

function AnalyticsSkeleton() {
  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        {[0, 1, 2, 3, 4].map((i) => (
          <Skeleton key={i} variant="card" className="h-[104px]" />
        ))}
      </div>
      <Skeleton variant="card" className="h-48" />
      <Skeleton variant="card" className="h-48" />
    </div>
  );
}

/** True when the whole report carries no signal — no learners and an empty funnel/distribution. */
function isAnalyticsEmpty(a: CourseAnalytics): boolean {
  const noLearners = !a.total_learners.available || a.total_learners.value === null || a.total_learners.value === 0;
  const noFunnel = a.lesson_drop_off.length === 0;
  const noDistribution = COMPLETION_BUCKETS.every((b) => (a.completion_distribution[b] ?? 0) === 0);
  return noLearners && noFunnel && noDistribution;
}

/** Per-course engagement analytics: watch-time, drop-off funnel, inactive count and completion mix. */
export function CourseAnalyticsSection({ courseId }: { courseId: string }) {
  const { t, locale } = useI18n();
  const query = useCourseAnalytics(courseId);

  return (
    <QueryState<CourseAnalytics>
      query={query}
      loading={<AnalyticsSkeleton />}
      isEmpty={isAnalyticsEmpty}
      empty={<EmptyState icon={<Users className="size-8" />} title={t("teach.analytics.empty")} />}
    >
      {(analytics) => (
        <div className="space-y-8">
          <section aria-label={t("teach.analytics.headline")}>
            <div className="stagger-in grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
              <MetricCard label={t("teach.analytics.totalLearners")} metric={analytics.total_learners} format="number" icon={Users} />
              <WatchTimeCard label={t("teach.analytics.totalWatch")} metric={analytics.watch_time.total_watched_seconds} icon={Clock} />
              <WatchTimeCard label={t("teach.analytics.avgWatch")} metric={analytics.watch_time.avg_watched_seconds_per_learner} icon={Clock} />
              <MetricCard label={t("teach.analytics.inactiveLearners")} metric={analytics.inactive_learners.count} format="number" icon={UserMinus} />
              <MetricCard label={t("teach.analytics.certificatesIssued")} metric={analytics.certificates_issued} format="number" icon={Award} />
            </div>
            <p className="mt-2 text-xs text-muted-foreground">
              {t("teach.analytics.inactiveHint").replace("{days}", formatNumber(analytics.inactive_learners.window_days, locale))}
            </p>
          </section>

          <DropOffPanel rows={analytics.lesson_drop_off} />
          <DistributionPanel distribution={analytics.completion_distribution} />
        </div>
      )}
    </QueryState>
  );
}

/** Lesson drop-off funnel: started vs completed per lesson, biggest drop-off flagged. */
function DropOffPanel({ rows }: { rows: CourseAnalytics["lesson_drop_off"] }) {
  const { t, locale } = useI18n();
  // The single worst lesson to fix. Only flag it when at least one learner actually dropped, so an
  // all-zero funnel never sprouts a meaningless "biggest drop-off" badge.
  const maxDropOff = rows.reduce((max, r) => Math.max(max, r.drop_off), 0);

  return (
    <section aria-label={t("teach.analytics.dropOff")}>
      <div className="mb-1 flex items-center gap-2">
        <TrendingDown className="size-5 text-primary" aria-hidden />
        <h2 className="font-serif text-lg font-semibold">{t("teach.analytics.dropOff")}</h2>
      </div>
      <p className="mb-3 text-sm text-muted-foreground">{t("teach.analytics.dropOffCaption")}</p>

      {rows.length === 0 ? (
        <EmptyState icon={<TrendingDown className="size-8" />} title={t("teach.analytics.dropOffEmpty")} />
      ) : (
        <Card>
          <CardContent className="space-y-4 p-5">
            {rows.map((row, index) => {
              const pct = row.started > 0 ? Math.round((row.completed / row.started) * 100) : 0;
              const biggest = maxDropOff > 0 && row.drop_off === maxDropOff;
              return (
                <div key={row.lesson?.id ?? `removed-${index}`} className="space-y-1.5">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="min-w-0 flex-1 truncate text-sm font-medium">
                      {row.lesson?.title ?? t("teach.analytics.unknownLesson")}
                    </span>
                    {biggest ? <Badge variant="destructive">{t("teach.analytics.biggestDropOff")}</Badge> : null}
                  </div>
                  <div className="flex items-center gap-3">
                    <div
                      className="h-2 flex-1 overflow-hidden rounded-full bg-muted"
                      role="progressbar"
                      aria-valuenow={pct}
                      aria-valuemin={0}
                      aria-valuemax={100}
                      aria-label={`${row.lesson?.title ?? t("teach.analytics.unknownLesson")}: ${pct}%`}
                    >
                      <div className="h-full rounded-full bg-primary" style={{ width: `${pct}%` }} aria-hidden />
                    </div>
                    <span className="shrink-0 text-xs tabular-nums text-muted-foreground">
                      {t("teach.analytics.started")}: {formatNumber(row.started, locale)} · {t("teach.analytics.completed")}:{" "}
                      {formatNumber(row.completed, locale)} · {t("teach.analytics.dropOffLabel")}: {formatNumber(row.drop_off, locale)}
                    </span>
                  </div>
                </div>
              );
            })}
          </CardContent>
        </Card>
      )}
    </section>
  );
}

/** Completion distribution: learners bucketed by how much of the course they have completed. */
function DistributionPanel({ distribution }: { distribution: CourseAnalytics["completion_distribution"] }) {
  const { t, locale } = useI18n();
  const counts = COMPLETION_BUCKETS.map((bucket) => distribution[bucket] ?? 0);
  const maxCount = counts.reduce((max, c) => Math.max(max, c), 0);
  const empty = maxCount === 0;

  return (
    <section aria-label={t("teach.analytics.distribution")}>
      <h2 className="mb-1 font-serif text-lg font-semibold">{t("teach.analytics.distribution")}</h2>
      <p className="mb-3 text-sm text-muted-foreground">{t("teach.analytics.distributionCaption")}</p>

      {empty ? (
        <EmptyState title={t("teach.analytics.distributionEmpty")} />
      ) : (
        <Card>
          <CardContent className="space-y-3 p-5">
            {COMPLETION_BUCKETS.map((bucket, i) => {
              const count = counts[i];
              const pct = maxCount > 0 ? Math.round((count / maxCount) * 100) : 0;
              return (
                <div key={bucket} className="flex items-center gap-3">
                  <span className="w-16 shrink-0 text-xs tabular-nums text-muted-foreground">{bucket}%</span>
                  <div className="h-3 flex-1 overflow-hidden rounded-full bg-muted">
                    <div className="h-full rounded-full bg-primary" style={{ width: `${pct}%` }} aria-hidden />
                  </div>
                  <span className="w-16 shrink-0 text-end text-xs tabular-nums">
                    {formatNumber(count, locale)} {t("teach.analytics.learnersUnit")}
                  </span>
                </div>
              );
            })}
          </CardContent>
        </Card>
      )}
    </section>
  );
}
