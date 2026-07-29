'use client';

import { useEffect, useMemo, useState } from 'react';

import { Button, Drawer, Spinner } from '@/components/ui';
import { flattenLessons, isLessonNavigable } from '@/lib/learning/player-api';
import { useCurriculum, useProgressSummary } from '@/lib/learning/player-hooks';
import {
  LearningPlayerI18nProvider,
  useLearningPlayerI18n,
} from '@/lib/learning/player-i18n';
import { CurriculumSidebar } from './CurriculumSidebar';
import { LessonView } from './LessonView';
import { PlayerError } from './PlayerError';
import { ProgressDisplay } from './ProgressDisplay';

export interface CoursePlayerShellProps {
  courseId: string;
  /** App locale, passed down to the player's module-local i18n. */
  locale?: string | null;
  /** Deep-link into a specific lesson; otherwise resumes / first lesson. */
  initialLessonId?: string | null;
  onLaunchAssessment?: (assessmentId: string) => void;
  onLaunchAssignment?: (assignmentId: string) => void;
}

/** Top-level learner course player. Wrap-and-compose; the integrator mounts this in the route. */
export function CoursePlayerShell(props: CoursePlayerShellProps): React.ReactElement {
  return (
    <LearningPlayerI18nProvider locale={props.locale}>
      <CoursePlayerShellInner {...props} />
    </LearningPlayerI18nProvider>
  );
}

function CoursePlayerShellInner({
  courseId,
  initialLessonId,
  onLaunchAssessment,
  onLaunchAssignment,
}: CoursePlayerShellProps): React.ReactElement {
  const { t, dir } = useLearningPlayerI18n();
  const curriculum = useCurriculum(courseId);
  const summary = useProgressSummary(courseId);

  const lessons = useMemo(() => flattenLessons(curriculum.data), [curriculum.data]);
  const firstNavigable = useMemo(() => lessons.find(isLessonNavigable)?.id ?? null, [lessons]);

  const [activeLessonId, setActiveLessonId] = useState<string | null>(initialLessonId ?? null);
  const [drawerOpen, setDrawerOpen] = useState(false);

  // Default the active lesson to server resume, then first navigable lesson.
  useEffect(() => {
    if (activeLessonId) return;
    const resume = summary.data?.resume_lesson_id ?? null;
    const next = resume && lessons.some((l) => l.id === resume) ? resume : firstNavigable;
    if (next) setActiveLessonId(next);
  }, [activeLessonId, summary.data?.resume_lesson_id, firstNavigable, lessons]);

  const navigate = (lessonId: string) => {
    setActiveLessonId(lessonId);
    setDrawerOpen(false);
  };

  if (curriculum.isLoading) {
    return (
      <div
        dir={dir}
        className="flex min-h-[16rem] items-center justify-center"
        data-testid="player-loading"
      >
        <Spinner aria-label={t('player.loading')} />
      </div>
    );
  }

  if (curriculum.isError || !curriculum.data) {
    return (
      <div dir={dir} className="p-4">
        <PlayerError
          message={t('player.error.curriculum')}
          onRetry={() => void curriculum.refetch()}
          isRetrying={curriculum.isFetching}
        />
      </div>
    );
  }

  const progressPercentage =
    summary.data?.progress_percentage ?? curriculum.data.enrollment.progress_percentage;

  const sidebar = (
    <CurriculumSidebar
      curriculum={curriculum.data}
      activeLessonId={activeLessonId}
      onSelectLesson={navigate}
    />
  );

  return (
    <div dir={dir} className="mx-auto max-w-6xl" data-testid="course-player">
      <header className="flex items-center justify-between gap-4 border-b border-neutral-200 p-4">
        <div className="min-w-0">
          <h1 className="truncate text-xl font-semibold">{curriculum.data.course.title}</h1>
        </div>
        <Button
          variant="ghost"
          className="lg:hidden"
          onClick={() => setDrawerOpen(true)}
          aria-label={t('player.openMenu')}
          data-testid="open-curriculum"
        >
          ☰
        </Button>
      </header>

      <div className="p-4">
        <ProgressDisplay
          progressPercentage={progressPercentage}
          completedLessons={summary.data?.completed_lessons}
          totalLessons={summary.data?.total_lessons}
          courseCompleted={summary.data?.course_completed}
          resumeLessonId={summary.data?.resume_lesson_id}
          onResume={navigate}
        />
      </div>

      <div className="grid gap-6 p-4 lg:grid-cols-[18rem_1fr]">
        {/* Persistent sidebar on large screens */}
        <aside className="hidden lg:block" data-testid="sidebar-desktop">
          {sidebar}
        </aside>

        {/* Drawer sidebar on small screens */}
        <Drawer
          open={drawerOpen}
          onClose={() => setDrawerOpen(false)}
          direction={dir === 'rtl' ? 'right' : 'left'}
          aria-label={t('player.curriculum')}
        >
          {sidebar}
        </Drawer>

        <main data-testid="lesson-panel">
          {activeLessonId ? (
            <LessonView
              courseId={courseId}
              curriculum={curriculum.data}
              lessonId={activeLessonId}
              onNavigate={navigate}
              onLaunchAssessment={onLaunchAssessment}
              onLaunchAssignment={onLaunchAssignment}
            />
          ) : (
            <p className="text-sm text-neutral-500">{t('player.loading')}</p>
          )}
        </main>
      </div>
    </div>
  );
}
