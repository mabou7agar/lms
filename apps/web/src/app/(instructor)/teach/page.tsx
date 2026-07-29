"use client";

import { BookPlus, Presentation, Users } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { PageHeader } from "@/components/student/page-header";
import { ActivitySection } from "@/components/teach/activity-section";
import { AlertsSection } from "@/components/teach/alerts-section";
import { ChangeSummaryDialog } from "@/components/teach/change-summary-dialog";
import { CoursePerformanceSection } from "@/components/teach/course-performance-section";
import { DashboardSection } from "@/components/teach/dashboard-section";
import { OverviewSection } from "@/components/teach/overview-section";
import { ReadinessDialog } from "@/components/teach/readiness-dialog";
import { Button } from "@/components/ui/button";
import { useI18n } from "@/lib/i18n/i18n-context";

/**
 * Instructor Dashboard 2.0.
 *
 * Each section owns its own query and its own loading, empty and error state. That is deliberate:
 * the page fans out to four independent endpoints, and one of them failing must not blank the
 * three that succeeded. An instructor whose alerts endpoint is down should still see their
 * overview and course table.
 *
 * The readiness and change-summary dialogs are hoisted to the page rather than living inside each
 * table row: exactly one can be open at a time, so one instance each is correct, and it lets the
 * alerts panel open the same readiness dialog the performance table does.
 */
export default function TeachDashboardPage() {
  const { t } = useI18n();
  const [readinessCourseId, setReadinessCourseId] = useState<string | null>(null);
  const [changesCourseId, setChangesCourseId] = useState<string | null>(null);

  return (
    <div className="space-y-8">
      <PageHeader
        eyebrow="INSTRUCTOR"
        icon="LayoutDashboard"
        title={t("teach.dashboard.title")}
        subtitle={t("teach.dashboard.subtitle")}
        action={
          <div className="flex flex-wrap gap-2">
            <Button asChild size="sm">
              <Link href="/teach/courses">
                <BookPlus className="size-4" aria-hidden /> {t("teach.quick.newCourse")}
              </Link>
            </Button>
            <Button asChild variant="outline" size="sm">
              <Link href="/teach/courses">
                <Presentation className="size-4" aria-hidden /> {t("nav.teachCourses")}
              </Link>
            </Button>
            <Button asChild variant="outline" size="sm">
              <Link href="/teach/students">
                <Users className="size-4" aria-hidden /> {t("nav.teachStudents")}
              </Link>
            </Button>
          </div>
        }
      />

      <DashboardSection id="overview" title={t("teach.section.overview")}>
        <OverviewSection />
      </DashboardSection>

      <DashboardSection id="performance" title={t("teach.section.performance")}>
        <CoursePerformanceSection
          onReviewReadiness={setReadinessCourseId}
          onViewChanges={setChangesCourseId}
        />
      </DashboardSection>

      {/* Side by side from lg up; stacked below, activity first — what you did reads before what
          needs doing when the screen is too small to show both at once. */}
      <div className="grid gap-8 lg:grid-cols-2">
        <DashboardSection id="activity" title={t("teach.section.activity")}>
          <ActivitySection />
        </DashboardSection>

        <DashboardSection id="alerts" title={t("teach.section.alerts")}>
          <AlertsSection onReviewReadiness={setReadinessCourseId} />
        </DashboardSection>
      </div>

      <ReadinessDialog
        courseId={readinessCourseId}
        onOpenChange={(open) => !open && setReadinessCourseId(null)}
      />
      <ChangeSummaryDialog
        courseId={changesCourseId}
        onOpenChange={(open) => !open && setChangesCourseId(null)}
      />
    </div>
  );
}
