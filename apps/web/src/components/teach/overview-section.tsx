"use client";

import {
  Activity,
  Archive,
  BookOpen,
  CheckCircle2,
  CircleDollarSign,
  FileEdit,
  GraduationCap,
  Send,
  ShieldAlert,
  TrendingUp,
  Users,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { QueryState } from "@/components/student/query-state";
import { Skeleton } from "@/components/ui/skeleton";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { DashboardOverview, OverviewMetricKey } from "@/lib/teach/api";
import { useDashboardOverview } from "@/lib/teach/hooks";
import type { MetricFormat } from "@/lib/teach/format";
import { MetricCard } from "./metric-card";

/**
 * The metric grid, declared once.
 *
 * Order is the reading order an instructor wants: what they have, who is on it, how it is going,
 * then the two things the platform cannot yet answer. Revenue and at-risk learners are LAST and
 * still rendered — hiding an unavailable metric would leave an instructor wondering whether the
 * platform tracks it at all, where showing it with a reason answers that.
 */
const METRICS: ReadonlyArray<{
  key: OverviewMetricKey;
  labelKey: string;
  format: MetricFormat;
  icon: LucideIcon;
}> = [
  { key: "total_courses", labelKey: "teach.metric.totalCourses", format: "number", icon: BookOpen },
  { key: "published_courses", labelKey: "teach.metric.publishedCourses", format: "number", icon: Send },
  { key: "draft_courses", labelKey: "teach.metric.draftCourses", format: "number", icon: FileEdit },
  { key: "archived_courses", labelKey: "teach.metric.archivedCourses", format: "number", icon: Archive },
  { key: "total_learners", labelKey: "teach.metric.totalLearners", format: "number", icon: Users },
  { key: "active_learners", labelKey: "teach.metric.activeLearners", format: "number", icon: Activity },
  { key: "completion_rate", labelKey: "teach.metric.completionRate", format: "percent", icon: CheckCircle2 },
  { key: "average_progress", labelKey: "teach.metric.averageProgress", format: "percent", icon: TrendingUp },
  { key: "assessment_pass_rate", labelKey: "teach.metric.passRate", format: "percent", icon: GraduationCap },
  { key: "revenue", labelKey: "teach.metric.revenue", format: "number", icon: CircleDollarSign },
  { key: "at_risk_learners", labelKey: "teach.metric.atRisk", format: "number", icon: ShieldAlert },
];

function OverviewSkeleton() {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
      {METRICS.map((metric) => (
        <Skeleton key={metric.key} variant="card" className="h-[104px]" />
      ))}
    </div>
  );
}

export function OverviewSection() {
  const { t } = useI18n();
  const query = useDashboardOverview();

  return (
    <QueryState<DashboardOverview> query={query} loading={<OverviewSkeleton />}>
      {(overview) => (
        <div className="stagger-in grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {METRICS.map(({ key, labelKey, format, icon }) => (
            <MetricCard
              key={key}
              label={t(labelKey)}
              metric={overview[key]}
              format={format}
              icon={icon}
            />
          ))}
        </div>
      )}
    </QueryState>
  );
}
