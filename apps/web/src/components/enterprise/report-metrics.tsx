"use client";

import {
  Users, BookOpen, PlayCircle, CheckCircle2, Gauge, Clock, Timer, UserX, ShieldCheck, ShieldX,
  Award, Armchair,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { ManagerReport } from "@/lib/enterprise/manager-api";
import { StatCard } from "@/components/student/stat-card";

/** Format a duration given in seconds as a compact "Hh Mm" (or "Mm") string. */
function formatDuration(seconds: number): string {
  const total = Math.max(0, Math.round(seconds));
  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  if (hours > 0) return `${hours}h ${minutes}m`;
  return `${minutes}m`;
}

/** Renders every metric from the ManagerReport as accessible stat cards. */
export function ReportMetrics({ report }: { report: ManagerReport }) {
  const { t } = useI18n();

  const seatUtil =
    report.seats && report.seats.purchased > 0
      ? `${Math.round((report.seats.used / report.seats.purchased) * 100)}%`
      : "—";

  const metrics: Array<{ key: string; label: string; value: string | number; icon: LucideIcon }> = [
    { key: "learners", label: t("manager.report.learners"), value: report.learners, icon: Users },
    { key: "enrollments", label: t("manager.report.enrollments"), value: report.enrollments, icon: BookOpen },
    { key: "started", label: t("manager.report.started"), value: report.started, icon: PlayCircle },
    { key: "completions", label: t("manager.report.completions"), value: report.completions, icon: CheckCircle2 },
    { key: "avgProgress", label: t("manager.report.avgProgress"), value: `${Math.round(report.avg_progress)}%`, icon: Gauge },
    { key: "watchTime", label: t("manager.report.watchTime"), value: formatDuration(report.watch_time_seconds), icon: Clock },
    {
      key: "avgWatchTime",
      label: t("manager.report.avgWatchTime"),
      value: formatDuration(report.avg_watch_time_seconds_per_learner),
      icon: Timer,
    },
    { key: "inactive", label: t("manager.report.inactive"), value: report.inactive_learners, icon: UserX },
    { key: "passed", label: t("manager.report.passed"), value: report.assessments_passed, icon: ShieldCheck },
    { key: "failed", label: t("manager.report.failed"), value: report.assessments_failed, icon: ShieldX },
    { key: "certificates", label: t("manager.report.certificates"), value: report.certificates_issued, icon: Award },
    { key: "seatUtilization", label: t("manager.report.seatUtilization"), value: seatUtil, icon: Armchair },
  ];

  return (
    <div className="stagger-in grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      {metrics.map((m) => (
        <StatCard key={m.key} label={m.label} value={m.value} icon={m.icon} />
      ))}
    </div>
  );
}
