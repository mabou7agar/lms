"use client";

import { useI18n } from "@/lib/i18n/i18n-context";
import { formatExpiry, isExpiringSoon } from "@/lib/commerce/expiry";
import { useMyLearning } from "@/lib/student/hooks";
import { ExpiryBanner } from "@/components/commerce/expiry-banner";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { CourseProgressCard } from "@/components/student/course-progress-card";
import { EmptyState } from "@/components/states/empty-state";

export default function MyLearningPage() {
  const { t, locale } = useI18n();
  const query = useMyLearning();

  return (
    <div>
      <PageHeader eyebrow="MY LEARNING" icon="GraduationCap" title={t("student.myLearning.title")} subtitle={t("student.myLearning.subtitle")} />
      <QueryState
        query={query}
        isEmpty={(d) => d.length === 0}
        empty={<EmptyState title={t("student.myLearning.empty")} />}
      >
        {(items) => {
          // Company-granted access is the only kind that runs out, and losing a half-finished course
          // without warning is exactly the surprise this banner exists to prevent.
          const ending = items.filter((it) => !it.expired && isExpiringSoon(it.expires_at));
          const ended = items.filter((it) => it.expired);

          return (
          <div className="space-y-6">
          {ending.length > 0 ? (
            <ExpiryBanner
              title={t("student.myLearning.expiringBanner").replace("{count}", String(ending.length))}
              detail={ending.map((it) => `${it.course.title} · ${formatExpiry(it.expires_at, locale)}`).join(" · ")}
            />
          ) : null}
          {ended.length > 0 ? (
            <ExpiryBanner
              tone="expired"
              title={t("student.myLearning.expiredBanner").replace("{count}", String(ended.length))}
              detail={t("student.accessEndedHint")}
            />
          ) : null}

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
          </div>
          );
        }}
      </QueryState>
    </div>
  );
}
