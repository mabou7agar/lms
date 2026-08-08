"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { PageHeader } from "@/components/student/page-header";
import { Button } from "@/components/ui/button";
import { CourseAnalyticsSection } from "@/components/teach/course-analytics-section";

export default function TeachCourseAnalyticsPage() {
  const { t } = useI18n();
  const params = useParams<{ public_id: string }>();

  return (
    <div className="space-y-6">
      <Button asChild variant="ghost" size="sm" className="mb-2">
        <Link href={`/teach/courses/${params.public_id}`}>
          <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden /> {t("teach.learner.back")}
        </Link>
      </Button>

      <PageHeader
        eyebrow="INSTRUCTOR"
        icon="BarChart3"
        title={t("teach.analytics.title")}
        subtitle={t("teach.analytics.subtitle")}
      />

      <CourseAnalyticsSection courseId={params.public_id} />
    </div>
  );
}
