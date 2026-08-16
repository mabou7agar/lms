"use client";

import { useI18n } from "@/lib/i18n/i18n-context";
import { useMyLearning } from "@/lib/student/hooks";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { CourseProgressCard } from "@/components/student/course-progress-card";
import { EmptyState } from "@/components/states/empty-state";

export default function MyLearningPage() {
  const { t } = useI18n();
  const query = useMyLearning();

  return (
    <div>
      <PageHeader eyebrow="MY LEARNING" icon="GraduationCap" title={t("student.myLearning.title")} subtitle={t("student.myLearning.subtitle")} />
      <QueryState
        query={query}
        isEmpty={(d) => d.length === 0}
        empty={<EmptyState title={t("student.myLearning.empty")} />}
      >
        {(items) => (
          <div className="stagger-in grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {items.map((it) => (
              <CourseProgressCard
                key={it.enrollment_id}
                title={it.course.title}
                progress={it.progress_percentage}
                status={it.status}
                expired={it.expired}
                continueHref={`/learn/${it.course.id}`}
              />
            ))}
          </div>
        )}
      </QueryState>
    </div>
  );
}
