"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { PlayCircle } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useLearnCourse } from "@/lib/learning/hooks";
import type { LearnCourse } from "@/lib/learning/api";
import { RequireAuth } from "@/lib/auth/guards";
import { CurriculumSidebar } from "@/components/learning/curriculum-sidebar";
import { CourseCommunityPanel } from "@/components/community/course-community-panel";
import { PageHeader } from "@/components/student/page-header";
import { ProgressBar } from "@/components/student/progress-bar";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { LoadingState } from "@/components/states/loading-state";
import { ErrorState } from "@/components/states/error-state";

function firstOpenLesson(course: LearnCourse): string | null {
  const lessons = course.sections.flatMap((s) => s.lessons);
  const next = lessons.find((l) => !l.completed && !l.locked) ?? lessons.find((l) => !l.locked);
  return next?.id ?? null;
}

function LearnInner() {
  const { t } = useI18n();
  const params = useParams<{ public_id: string }>();
  const query = useLearnCourse(params.public_id);

  if (query.isPending) return <LoadingState />;
  if (query.isError) {
    return (
      <ErrorState
        title={t("learn.notEnrolled")}
        message={errorMessage(query.error, t("common.error"))}
        onRetry={() => query.refetch()}
      />
    );
  }

  const course = query.data;
  const open = firstOpenLesson(course);

  return (
    <div>
      <PageHeader
        title={course.course.title}
        action={
          open ? (
            <Button asChild>
              <Link href={`/lessons/${open}`}>
                <PlayCircle className="size-4" aria-hidden />{" "}
                {course.enrollment.progress_percentage > 0 ? t("learn.continue") : t("learn.start")}
              </Link>
            </Button>
          ) : null
        }
      />

      <div className="grid gap-6 lg:grid-cols-[320px_1fr]">
        <Card className="lg:sticky lg:top-20 lg:self-start">
          <CardContent className="space-y-4 p-4">
            <div className="space-y-1.5">
              <div className="flex items-center justify-between text-xs text-muted-foreground">
                <span>{t("learn.progress")}</span>
                <span className="tabular-nums">{Math.round(course.enrollment.progress_percentage)}%</span>
              </div>
              <ProgressBar value={course.enrollment.progress_percentage} />
            </div>
            <CurriculumSidebar sections={course.sections} coursePublicId={course.course.id} />
          </CardContent>
        </Card>

        <Card>
          <CardContent className="flex min-h-64 flex-col items-center justify-center gap-4 p-8 text-center">
            <p className="max-w-md text-muted-foreground">{course.course.title}</p>
            {open ? (
              <Button asChild size="lg">
                <Link href={`/lessons/${open}`}>
                  {course.enrollment.progress_percentage > 0 ? t("learn.continue") : t("learn.start")}
                </Link>
              </Button>
            ) : null}
          </CardContent>
        </Card>
      </div>

      {/* Enrolled-learner community: Q&A + Discussion */}
      <div className="mt-8">
        <CourseCommunityPanel courseId={course.course.id} />
      </div>
    </div>
  );
}

export default function CourseLearnPage() {
  return (
    <RequireAuth>
      <LearnInner />
    </RequireAuth>
  );
}
